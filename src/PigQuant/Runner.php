<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Retrying;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Pig\Async\Future;
use Pig\CodingAgent\Logger;
use Quant\Engine;
use Quant\Exchange\ExchangeError;
use Quant\Exchange\StreamUpdate;
use Throwable;

/**
 * Keeps the engine fed on pig's event loop: history over `HttpClient` (through `Retrying`), then
 * the exchange's stream (`Stream`) in a coroutine of its own, so the TUI, the web UI and the
 * model keep running while candles arrive. After every reopening of the stream the gap is
 * filled from REST.
 *
 * What ends the stream for good — the exchange getting something wrong, the network gone for
 * ten attempts — stops the runner and is kept as `problem()` for `/quant` to show: the dry run
 * does not pretend to be trading when it is not.
 */
final class Runner
{
    private const float HTTP_TIMEOUT = 20.0;

    private ?AbortController $abort = null;

    private ?Future $loop = null;

    private ?Stream $stream = null;

    private ?string $problem = null;

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
        return $this->stream?->connectedAt();
    }

    public function reconnects(): int
    {
        return $this->stream?->reconnects() ?? 0;
    }

    /** @return array{attempt: int, error: string, nextIn: float}|null */
    public function retrying(): ?array
    {
        return $this->stream?->retrying();
    }

    /** Load history for every symbol, then start the stream. Throws when the history cannot be read. */
    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->problem = null;
        $this->loadHistory();
        $this->abort = new AbortController();
        $signal = $this->abort->signal;
        $engine = $this->engine;

        $this->stream = new Stream(
            $engine->exchange,
            $engine->symbols,
            $engine->interval,
            static fn (StreamUpdate $update) => $engine->onCandle($update->symbol, $update->candle),
            $this->loadHistory(...),
        );

        $this->loop = Async::spawn(function () use ($signal): void {
            try {
                $this->stream->run($signal);
            } catch (AbortError) {
                // stop() — nothing to report.
            } catch (Throwable $error) {
                // Kept, not rethrown: an error left in a spawned coroutine surfaces when the root
                // coroutine ends, which would take pig down at exit over a stream nobody is
                // watching any more. `/quant` and the panel show it; `/quant start` clears it.
                $this->problem = $error->getMessage();
                Logger::error('[quant] stopped: ' . $error->getMessage());
            } finally {
                Logger::info('[quant] stream stopped');
            }
        });
    }

    /** Abort the stream's coroutine; it closes the socket itself. */
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
            [$response, $body] = Retrying::fetch(
                $http,
                new Request($request->method, $request->url, $request->headers, $request->body),
                onRetry: static fn (int $attempt, string $reason, float $wait) => Logger::warning(sprintf('[quant] %s history: %s — trying again in %.0fs', $symbol, $reason, $wait)),
            );

            if (!$response->isSuccessful()) {
                throw new ExchangeError("{$exchange->name()} answered {$response->status} {$response->reason} for {$symbol} candles: " . substr($body, 0, 300));
            }

            $candles = $exchange->parseCandles($body, $this->engine->interval);
            $this->engine->seed($symbol, $candles);
            Logger::info(sprintf('[quant] %s %s: %d candles of history', $exchange->symbol($symbol), $this->engine->interval, count($candles)));
        }
    }
}
