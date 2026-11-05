<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\Async\AbortController;
use Pig\Async\AbortError;
use Pig\Async\Async;
use Pig\Async\Future;
use Pig\CodingAgent\Config as PigConfig;
use Pig\CodingAgent\Logger;
use Quant\Exchange\Exchange;
use Quant\Exchange\StreamUpdate;
use Quant\Spread\Observer;
use Quant\Spread\Sample;
use Quant\Spread\Stats;
use Throwable;

/**
 * The price gap between two exchanges, watched live: one `Stream` per exchange feeding one
 * `Observer`, every complete minute appended to `~/.pig/agent/quant/spread/<a>-<b>-<symbol>-<day>.jsonl`.
 * `report()` reads those files back and summarizes them (`Stats`).
 *
 * Observation only — nothing is traded. The question it answers is whether the gap ever clears
 * what a round trip on both exchanges costs, and for how long.
 */
final class SpreadRunner
{
    private ?AbortController $abort = null;

    /** @var list<Future> */
    private array $loops = [];

    /** @var list<Stream> */
    private array $streams = [];

    private ?Observer $observer = null;

    private ?string $problem = null;

    private int $written = 0;

    public function __construct(
        private readonly Exchange $a,
        private readonly Exchange $b,
        private readonly string $symbol,
        private readonly string $interval,
        private readonly float $thresholdBps,
    ) {
        if ($a->name() === $b->name()) {
            throw new \InvalidArgumentException('A spread is between two different exchanges.');
        }
    }

    public static function directory(): string
    {
        return PigConfig::home() . '/quant/spread';
    }

    public function isRunning(): bool
    {
        foreach ($this->loops as $loop) {
            if (!$loop->isComplete()) {
                return true;
            }
        }

        return false;
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
        $this->written = 0;
        $this->abort = new AbortController();
        $signal = $this->abort->signal;
        $observer = $this->observer = new Observer($this->a->name(), $this->b->name(), $this->symbol);
        $observer->onSample(function (Sample $sample): void {
            $this->append($sample);
        });

        $this->streams = [];
        $this->loops = [];

        foreach ([$this->a, $this->b] as $exchange) {
            $stream = new Stream(
                $exchange,
                [$this->symbol],
                $this->interval,
                static fn (StreamUpdate $update) => $observer->onCandle($exchange->name(), $update->candle),
                tag: 'quant spread',
            );
            $this->streams[] = $stream;
            $this->loops[] = Async::spawn(function () use ($stream, $signal, $exchange): void {
                try {
                    $stream->run($signal);
                } catch (AbortError) {
                    // stop()
                } catch (Throwable $error) {
                    $this->problem = "{$exchange->name()}: " . $error->getMessage();
                    Logger::error('[quant spread] stopped: ' . $this->problem);
                    // One side gone is no spread: the other side stops too.
                    $this->abort?->abort('the other stream failed');
                }
            });
        }
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
            'exchanges' => [$this->a->name(), $this->b->name()],
            'symbol' => $this->symbol,
            'interval' => $this->interval,
            'thresholdBps' => $this->thresholdBps,
            'liveBps' => $this->observer?->liveBps(),
            'latest' => $this->observer?->latest() ?? [],
            'minutesThisSession' => $this->written,
            'streams' => array_map(static fn (Stream $s): array => [
                'exchange' => $s->exchange->name(),
                'connectedAt' => $s->connectedAt(),
                'reconnects' => $s->reconnects(),
                'retrying' => $s->retrying(),
            ], $this->streams),
        ];
    }

    /**
     * The samples of the last `$days` days (today included) summarized.
     *
     * @return array<string, mixed> `Stats::summarize()` plus `exchanges`, `symbol`, `files`
     */
    public function report(int $days = 1): array
    {
        $samples = [];
        $files = [];

        for ($i = $days - 1; $i >= 0; $i--) {
            $file = $this->file(time() - $i * 86_400);

            if (!is_file($file)) {
                continue;
            }

            $files[] = $file;

            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $row = json_decode($line, true);

                if (is_array($row)) {
                    $samples[] = Sample::fromArray($row);
                }
            }
        }

        usort($samples, static fn (Sample $x, Sample $y): int => $x->time <=> $y->time);

        return Stats::summarize($samples, $this->thresholdBps) + [
            'exchanges' => [$this->a->name(), $this->b->name()],
            'symbol' => $this->symbol,
            'files' => $files,
        ];
    }

    /** The report as lines a person reads. @param array<string, mixed> $report @return list<string> */
    public static function lines(array $report): array
    {
        return Stats::lines($report, $report['exchanges'][0], $report['exchanges'][1], $report['symbol']);
    }

    private function append(Sample $sample): void
    {
        $file = $this->file(intdiv($sample->time, 1000));
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        if (file_put_contents($file, json_encode($sample->toArray(), JSON_PRESERVE_ZERO_FRACTION) . "\n", FILE_APPEND | LOCK_EX) === false) {
            throw new \RuntimeException("Could not write {$file}.");
        }

        $this->written++;
    }

    private function file(int $unixSeconds): string
    {
        $symbol = preg_replace('/[^A-Za-z0-9]+/', '', $this->symbol);

        return sprintf('%s/%s-%s-%s-%s.jsonl', self::directory(), $this->a->name(), $this->b->name(), $symbol, gmdate('Y-m-d', $unixSeconds));
    }
}
