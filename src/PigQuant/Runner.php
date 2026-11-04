<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\WebSocket;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Pig\Async\Future;
use Pig\CodingAgent\Logger;
use Quant\Engine;
use Quant\Exchange\ExchangeError;
use Throwable;

/**
 * Keeps the engine fed on pig's event loop: history over `HttpClient`, then the exchange's
 * stream over `WebSocket` in a coroutine of its own, so the TUI, the web UI and the model keep
 * running while candles arrive.
 *
 * A stream that closes (the exchange's 24-hour disconnect, a network blip) is reopened after a
 * short wait and the gap filled from REST; a stream that cannot be reached (a connect that times
 * out, a refused connection) is tried again with a wait that doubles up to a minute, ten times.
 * Each is said in the log and shown as `retrying()`. What the exchange itself gets wrong — a
 * refused request, a body that is not candles, an error on the stream — stops the runner at once
 * and is kept as `problem()` for `/quant` to show: the dry run does not pretend to be trading
 * when it is not.
 */
final class Runner
{
    /** The first wait before trying the stream again; doubled each failure up to the maximum. */
    private const float RETRY_WAIT = 3.0;

    private const float MAX_RETRY_WAIT = 60.0;

    /** Consecutive failures to reach the stream before the runner gives up and says so. */
    private const int MAX_FAILURES = 10;

    private const float HTTP_TIMEOUT = 20.0;

    private ?AbortController $abort = null;

    private ?Future $loop = null;

    private ?WebSocket $socket = null;

    private ?string $problem = null;

    private ?int $connectedAt = null;

    private int $reconnects = 0;

    /** @var array{attempt: int, error: string, nextIn: float}|null */
    private ?array $retrying = null;

    public function __construct(
        public readonly Engine $engine,
        private readonly int $warmupCandles,
    ) {
    }

    public function isRunning(): bool
    {
        return $this->loop !== null && !$this->loop->isComplete();
    }

    public function problem(): ?string
    {
        return $this->problem;
    }

    public function connectedAt(): ?int
    {
        return $this->connectedAt;
    }

    public function reconnects(): int
    {
        return $this->reconnects;
    }

    /**
     * The attempt under way when the stream cannot be reached, or null: `['attempt' => n,
     * 'error' => …, 'nextIn' => seconds]`.
     *
     * @return array{attempt: int, error: string, nextIn: float}|null
     */
    public function retrying(): ?array
    {
        return $this->retrying;
    }

    /** Load history for every symbol, then start the stream. Throws when the history cannot be read. */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->problem = null;
        $this->retrying = null;
        $this->loadHistory();
        $this->abort = new AbortController();
        $signal = $this->abort->signal;

        $this->loop = Async::spawn(function () use ($signal): void {
            $failures = 0;
            $wait = self::RETRY_WAIT;
            $history = false;

            try {
                while (!$signal->aborted()) {
                    try {
                        if ($history) {
                            $this->loadHistory();
                            $history = false;
                        }

                        $this->streamOnce($signal);
                        $failures = 0;
                        $wait = self::RETRY_WAIT;
                        $this->retrying = null;

                        if ($signal->aborted()) {
                            break;
                        }

                        // The exchange closes a stream every 24 hours; the network does when it likes.
                        $this->reconnects++;
                        $history = true;
                        Logger::warning('[quant] stream closed; reconnecting in ' . self::RETRY_WAIT . 's');
                        Async::delay(self::RETRY_WAIT);
                    } catch (AbortError | ExchangeError $error) {
                        throw $error;
                    } catch (Throwable $error) {
                        // The network, not the exchange: a connect that timed out, a refused
                        // connection, a TLS failure. Tried again with a growing wait, and said so;
                        // the exchange answering something wrong (`ExchangeError`) is not retried.
                        $failures++;

                        if ($failures >= self::MAX_FAILURES) {
                            throw new \RuntimeException("gave up after {$failures} attempts to reach the stream; last: " . $error->getMessage(), 0, $error);
                        }

                        $this->retrying = ['attempt' => $failures, 'error' => $error->getMessage(), 'nextIn' => $wait];
                        $history = true;
                        Logger::warning(sprintf('[quant] cannot reach the stream (attempt %d of %d): %s; retrying in %.0fs', $failures, self::MAX_FAILURES, $error->getMessage(), $wait));
                        Async::delay($wait);
                        $wait = min($wait * 2, self::MAX_RETRY_WAIT);
                    }
                }
            } catch (AbortError) {
                // stop() — nothing to report.
            } catch (Throwable $error) {
                // Kept, not rethrown: an error left in a spawned coroutine surfaces when the root
                // coroutine ends, which would take pig down at exit over a stream nobody is
                // watching any more. `/quant` and the panel show it; `/quant start` clears it.
                $this->problem = $error->getMessage();
                Logger::error('[quant] stopped: ' . $error->getMessage());
            } finally {
                $this->socket?->close();
                $this->socket = null;
                $this->connectedAt = null;
                $this->retrying = null;
                Logger::info('[quant] stream stopped');
            }
        });
    }

    /**
     * Abort the stream's coroutine; it closes the socket itself. Closing it from here, while
     * `receive()` still watches it, is the "Reader watches a closed stream" crash of pig's loop.
     */
    public function stop(): void
    {
        $this->abort?->abort('stopped');
    }

    private function loadHistory(): void
    {
        $exchange = $this->engine->exchange;
        $http = new HttpClient(timeout: self::HTTP_TIMEOUT);

        foreach ($this->engine->symbols as $symbol) {
            $request = $exchange->candlesRequest($symbol, $this->engine->interval, $this->warmupCandles);
            $response = $http->send(new Request($request->method, $request->url, $request->headers, $request->body));
            $body = $response->body->all();

            if (!$response->isSuccessful()) {
                throw new ExchangeError("{$exchange->name()} answered {$response->status} {$response->reason} for {$symbol} candles: " . substr($body, 0, 300));
            }

            $candles = $exchange->parseCandles($body);
            $this->engine->seed($symbol, $candles);
            Logger::info(sprintf('[quant] %s %s: %d candles of history', $exchange->symbol($symbol), $this->engine->interval, count($candles)));
        }
    }

    /** One connection, until the exchange or the network closes it. */
    private function streamOnce(\Pig\Async\AbortSignal $signal): void
    {
        $exchange = $this->engine->exchange;
        $url = $exchange->streamUrl($this->engine->symbols, $this->engine->interval);
        $this->socket = WebSocket::connect($url, [], $signal, self::HTTP_TIMEOUT);
        $this->connectedAt = time();
        Logger::info("[quant] streaming {$url}");

        while (($message = $this->socket->receive($signal)) !== null) {
            $update = $exchange->parseStreamMessage($message);

            if ($update !== null) {
                $this->engine->onCandle($update->symbol, $update->candle);
            }
        }

        Logger::info(sprintf('[quant] stream closed (%s %s)', $this->socket->closeCode() ?? '?', $this->socket->closeReason() ?? ''));
    }
}
