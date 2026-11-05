<?php

declare(strict_types=1);

namespace PigQuant;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Retrying;
use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\AbortSignal;
use Pig\Async\Async;
use Pig\Async\Deferred;
use Pig\Async\Future;
use Pig\CodingAgent\Logger;
use Quant\Exchange\ExchangeError;
use Quant\Funding\BinanceFunding;
use Quant\Funding\Monitor;
use Throwable;

/**
 * The funding rates of a few perpetuals, polled every few minutes and held against a threshold
 * (`Monitor`): when a symbol's annualized rate clears it a line is appended to
 * `~/.pig/agent/quant/funding/alerts.jsonl`, logged, and said to the person if a terminal is
 * there; the same when it comes back. The point is to know the few weeks a year a carry pays
 * without watching the exchange.
 *
 * REST, not a stream: the rate moves slowly and a poll every five minutes is plenty. A poll the
 * network loses is retried through `Retrying`; one that still fails is noted and the next poll
 * comes on time — the watcher does not stop over a lost minute.
 */
final class FundingWatcher
{
    private const float HTTP_TIMEOUT = 20.0;

    private ?AbortController $abort = null;

    private ?Future $loop = null;

    private ?string $problem = null;

    private int $polls = 0;

    /** @var array<string, array{rate: float, annualized: float, nextFundingTime: int, markPrice: float, at: int}> */
    private array $latest = [];

    /** @var array<string, string> symbol → the last poll's error, until the next poll succeeds */
    private array $failing = [];

    /** @var Closure(string): void|null */
    private ?Closure $notify = null;

    /**
     * @param list<string> $symbols
     * @param float $pollSeconds
     */
    public function __construct(
        private readonly string $restUrl,
        private readonly array $symbols,
        private readonly Monitor $monitor,
        private readonly float $pollSeconds,
        private readonly BinanceFunding $source = new BinanceFunding(),
        private readonly HttpClient $http = new HttpClient(timeout: self::HTTP_TIMEOUT),
    ) {
        if ($symbols === []) {
            throw new \InvalidArgumentException('The funding watcher needs at least one symbol (quant.funding.watch.symbols).');
        }

        if ($pollSeconds < 30) {
            throw new \InvalidArgumentException('quant.funding.watch.pollSeconds is at least 30.');
        }
    }

    public static function alertsFile(): string
    {
        return Funding::directory() . '/alerts.jsonl';
    }

    /** Where to say an alert out loud (a terminal's notify); logging happens regardless. @param Closure(string): void|null $notify */
    public function onAlert(?Closure $notify): void
    {
        $this->notify = $notify;
    }

    public function isRunning(): bool
    {
        return $this->loop !== null && !$this->loop->isComplete();
    }

    public function problem(): ?string
    {
        return $this->problem;
    }

    public function start(): void
    {
        if ($this->isRunning()) {
            return;
        }

        $this->problem = null;
        $this->polls = 0;
        $this->abort = new AbortController();
        $signal = $this->abort->signal;

        $this->loop = Async::spawn(function () use ($signal): void {
            try {
                while (!$signal->aborted()) {
                    $this->poll($signal);
                    self::sleep($this->pollSeconds, $signal);
                }
            } catch (AbortError) {
                // stop()
            } catch (Throwable $error) {
                $this->problem = $error->getMessage();
                Logger::error('[quant funding] stopped: ' . $this->problem);
            }
        });
    }

    public function stop(): void
    {
        $this->abort?->abort('stopped');
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return [
            'running' => $this->isRunning(),
            'problem' => $this->problem,
            'symbols' => $this->symbols,
            'alertAnnualizedPct' => $this->monitor->alertAnnualized,
            'pollSeconds' => $this->pollSeconds,
            'polls' => $this->polls,
            'latest' => $this->latest,
            'above' => $this->monitor->above(),
            'failing' => $this->failing,
        ];
    }

    /** Every symbol once; a symbol the exchange refuses stops the watcher, a lost poll does not. */
    private function poll(AbortSignal $signal): void
    {
        foreach ($this->symbols as $symbol) {
            if ($signal->aborted()) {
                return;
            }

            $symbol = $this->source->symbol($symbol);

            try {
                $current = $this->fetch($symbol, $signal);
            } catch (AbortError | ExchangeError $error) {
                throw $error;
            } catch (Throwable $error) {
                $this->failing[$symbol] = $error->getMessage();
                Logger::warning("[quant funding] {$symbol}: poll lost, {$error->getMessage()}; next poll in " . (int) $this->pollSeconds . 's');

                continue;
            }

            unset($this->failing[$symbol]);
            $now = (int) floor(microtime(true) * 1000);
            $this->latest[$symbol] = [
                'rate' => $current['rate'],
                'annualized' => $this->monitor->annualized($current['rate']),
                'nextFundingTime' => $current['nextFundingTime'],
                'markPrice' => $current['markPrice'],
                'at' => $now,
            ];

            $event = $this->monitor->update($symbol, $current['rate'], $now);

            if ($event !== null) {
                $this->alert($event);
            }
        }

        $this->polls++;
    }

    /** @return array{symbol: string, rate: float, nextFundingTime: int, markPrice: float, time: int} */
    private function fetch(string $symbol, AbortSignal $signal): array
    {
        $request = $this->source->currentRequest($this->restUrl, $symbol);
        [$response, $body] = Retrying::fetch(
            $this->http,
            new Request($request->method, $request->url, $request->headers, $request->body),
            attempts: 3,
            baseDelay: 2.0,
            signal: $signal,
        );

        if (!$response->isSuccessful()) {
            throw new ExchangeError("{$this->source->name()} answered {$response->status} {$response->reason} for {$symbol} funding: " . substr($body, 0, 300));
        }

        return $this->source->parseCurrent($body);
    }

    /** @param array{event: string, symbol: string, rate: float, annualized: float, time: int, since: int} $event */
    private function alert(array $event): void
    {
        $text = $event['event'] === 'above'
            ? sprintf('%s funding %+.4f%% per settlement ≈ %+.1f%% a year, over the %.0f%% threshold — a carry pays now', $event['symbol'], $event['rate'] * 100, $event['annualized'] * 100, $this->monitor->alertAnnualized * 100)
            : sprintf('%s funding back to %+.4f%% per settlement ≈ %+.1f%% a year, under the %.0f%% threshold after %s', $event['symbol'], $event['rate'] * 100, $event['annualized'] * 100, $this->monitor->alertAnnualized * 100, self::span($event['time'] - $event['since']));

        Logger::warning('[quant funding] ' . $text);

        $file = self::alertsFile();
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        if (file_put_contents($file, json_encode($event, JSON_PRESERVE_ZERO_FRACTION) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Could not write {$file}.");
        }

        if ($this->notify !== null) {
            try {
                ($this->notify)(($event['event'] === 'above' ? '🔔 ' : '🔕 ') . 'quant funding: ' . $text);
            } catch (Throwable $error) {
                // The terminal that started the watcher may be gone; the log and the file have it.
                Logger::debug('[quant funding] could not notify: ' . $error->getMessage());
            }
        }
    }

    /** A wait that `stop()` cuts short. */
    private static function sleep(float $seconds, AbortSignal $signal): void
    {
        if ($signal->aborted()) {
            return;
        }

        $deferred = new Deferred();
        $id = $signal->onAbort(static function () use ($deferred): void {
            if (!$deferred->future->isComplete()) {
                $deferred->complete(null);
            }
        });

        Async::spawn(static function () use ($seconds, $deferred): void {
            Async::delay($seconds);

            if (!$deferred->future->isComplete()) {
                $deferred->complete(null);
            }
        });

        try {
            $deferred->future->await();
        } finally {
            $signal->removeListener($id);
        }
    }

    private static function span(int $ms): string
    {
        $minutes = intdiv($ms, 60_000);

        return $minutes >= 1440 ? sprintf('%.1f days', $minutes / 1440) : ($minutes >= 60 ? sprintf('%.1f hours', $minutes / 60) : "{$minutes} min");
    }
}
