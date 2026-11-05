<?php

declare(strict_types=1);

namespace PigQuant;

use Closure;
use Pig\Ai\Http\WebSocket;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\CodingAgent\Logger;
use Quant\Exchange\Exchange;
use Quant\Exchange\ExchangeError;
use Quant\Exchange\StreamUpdate;
use Throwable;

/**
 * One exchange's candle stream, kept open on pig's event loop: connect, send the subscriptions,
 * run the heartbeat, hand every update to `$onUpdate` — and when the exchange or the network
 * closes it, or it cannot be reached, open it again. What the exchange gets wrong
 * (`ExchangeError`) ends the stream; the host decides what that means.
 *
 * Shared by the dry run's `Runner` (one stream) and the `SpreadRunner` (two).
 */
final class Stream
{
    private const float RETRY_WAIT = 3.0;

    private const float MAX_RETRY_WAIT = 60.0;

    private const int MAX_FAILURES = 10;

    private const float CONNECT_TIMEOUT = 20.0;

    private ?WebSocket $socket = null;

    private ?int $connectedAt = null;

    private int $reconnects = 0;

    /** @var array{attempt: int, error: string, nextIn: float}|null */
    private ?array $retrying = null;

    /**
     * @param list<string> $symbols
     * @param Closure(StreamUpdate): void $onUpdate
     * @param Closure(): void|null $onReconnect before each reopening, after a close: the gap is the host's to fill
     */
    public function __construct(
        public readonly Exchange $exchange,
        private readonly array $symbols,
        private readonly string $interval,
        private readonly Closure $onUpdate,
        private readonly ?Closure $onReconnect = null,
        private readonly string $tag = 'quant',
    ) {
    }

    public function connectedAt(): ?int
    {
        return $this->connectedAt;
    }

    public function reconnects(): int
    {
        return $this->reconnects;
    }

    /** @return array{attempt: int, error: string, nextIn: float}|null */
    public function retrying(): ?array
    {
        return $this->retrying;
    }

    /**
     * Run until `$signal` aborts. Throws what ends the stream for good: an `ExchangeError`, or
     * the network after `MAX_FAILURES` attempts. `AbortError` is let through for the host.
     */
    public function run(AbortSignal $signal): void
    {
        $failures = 0;
        $wait = self::RETRY_WAIT;
        $reopening = false;

        try {
            while (!$signal->aborted()) {
                try {
                    if ($reopening) {
                        $reopening = false;

                        if ($this->onReconnect !== null) {
                            ($this->onReconnect)();
                        }
                    }

                    $this->once($signal);
                    $failures = 0;
                    $wait = self::RETRY_WAIT;
                    $this->retrying = null;

                    if ($signal->aborted()) {
                        break;
                    }

                    // The exchange closes a stream every 24 hours; the network does when it likes.
                    $this->reconnects++;
                    $reopening = true;
                    Logger::warning("[{$this->tag}] {$this->exchange->name()} stream closed; reconnecting in " . self::RETRY_WAIT . 's');
                    Async::delay(self::RETRY_WAIT);
                } catch (AbortError | ExchangeError $error) {
                    throw $error;
                } catch (Throwable $error) {
                    // The network, not the exchange: a connect that timed out, a refused connection,
                    // a TLS failure. Tried again with a growing wait, and said so.
                    $failures++;

                    if ($failures >= self::MAX_FAILURES) {
                        throw new \RuntimeException("gave up after {$failures} attempts to reach the {$this->exchange->name()} stream; last: " . $error->getMessage(), 0, $error);
                    }

                    $this->retrying = ['attempt' => $failures, 'error' => $error->getMessage(), 'nextIn' => $wait];
                    $reopening = true;
                    Logger::warning(sprintf('[%s] cannot reach the %s stream (attempt %d of %d): %s; retrying in %.0fs', $this->tag, $this->exchange->name(), $failures, self::MAX_FAILURES, $error->getMessage(), $wait));
                    Async::delay($wait);
                    $wait = min($wait * 2, self::MAX_RETRY_WAIT);
                }
            }
        } finally {
            $this->socket?->close();
            $this->socket = null;
            $this->connectedAt = null;
            $this->retrying = null;
        }
    }

    /** One connection, until the exchange or the network closes it. */
    private function once(AbortSignal $signal): void
    {
        $exchange = $this->exchange;
        $url = $exchange->streamUrl($this->symbols, $this->interval);
        $socket = $this->socket = WebSocket::connect($url, [], $signal, self::CONNECT_TIMEOUT);
        $this->connectedAt = time();
        Logger::info("[{$this->tag}] streaming {$url}");

        // An exchange that names its streams in a message rather than the URL (OKX).
        foreach ($exchange->streamSubscriptions($this->symbols, $this->interval) as $subscription) {
            $socket->send($subscription, $signal);
        }

        // An exchange that drops a silent connection unless the client says `ping` (OKX): a
        // coroutine of its own, since `receive()` below holds this one. It ends with the socket.
        $heartbeat = $exchange->streamHeartbeat();

        if ($heartbeat !== null) {
            Async::spawn(static function () use ($socket, $signal, $heartbeat): void {
                while (!$signal->aborted() && $socket->isOpen()) {
                    Async::delay($heartbeat['every']);

                    if ($signal->aborted() || !$socket->isOpen()) {
                        break;
                    }

                    try {
                        $socket->send($heartbeat['message'], $signal);
                    } catch (Throwable) {
                        break; // the stream is going; `receive()` reports it
                    }
                }
            });
        }

        while (($message = $socket->receive($signal)) !== null) {
            $update = $exchange->parseStreamMessage($message);

            if ($update !== null) {
                ($this->onUpdate)($update);
            }
        }

        Logger::info(sprintf('[%s] %s stream closed (%s %s)', $this->tag, $exchange->name(), $socket->closeCode() ?? '?', $socket->closeReason() ?? ''));
    }
}
