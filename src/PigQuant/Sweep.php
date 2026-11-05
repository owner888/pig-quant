<?php

declare(strict_types=1);

namespace PigQuant;

use Closure;
use Pig\CodingAgent\Config as PigConfig;
use Pig\CodingAgent\Settings;
use Quant\Backtest\Backtester;
use Quant\Backtest\Report;
use Quant\Backtest\Sweep as Grid;

/**
 * A sweep as `/quant sweep` and `quant_sweep` ask for it: a backtest's symbol, interval and
 * range, with one or more overrides given as a range (`fast=10..30:5`) or a list (`slow=30,50,100`).
 * The candles are fetched once; every combination gets a fresh engine over the same candles.
 *
 * The summary — one row per combination — is kept as JSON under `~/.pig/agent/quant/sweeps/`.
 * Each run's full report is not saved: a sweep is for seeing the shape, and any one cell can be
 * re-run as a backtest.
 */
final class Sweep
{
    public static function directory(): string
    {
        return PigConfig::home() . '/quant/sweeps';
    }

    /**
     * @param array<string, string> $overrides by `Backtest::OVERRIDES` name; ranges and lists sweep, single values hold
     * @param Closure(string): void|null $progress a line at a time
     * @return array{results: list<array{overrides: array<string, int|float>, report: Report}>, sweeps: array<string, list<int|float>>, skipped: array<string, string>, file: string}
     */
    public static function run(?Settings $settings, string $symbol, string $interval, int $from, int $to, array $overrides, ?Closure $progress = null): array
    {
        foreach (array_keys($overrides) as $name) {
            if (!in_array($name, Backtest::OVERRIDES, true)) {
                throw new \InvalidArgumentException("'{$name}' is not a setting a sweep can override; there is: " . implode(', ', Backtest::OVERRIDES) . '.');
            }
        }

        ['sweeps' => $sweeps, 'fixed' => $fixed] = Grid::split($overrides);

        if ($sweeps === []) {
            throw new \InvalidArgumentException('Nothing to sweep: give at least one range (fast=10..30:5) or list (slow=30,50,100).');
        }

        $combinations = Grid::combinations($sweeps, $fixed);
        $base = Backtest::configure(Config::load($settings), $fixed);
        $base['symbols'] = Backtest::symbols($symbol);
        $base['interval'] = $interval;
        ['symbols' => $symbols, 'warmup' => $warmup, 'candles' => $candles] = Backtest::candles($base, $symbol, $interval, $from, $to, $progress);

        if ($progress !== null) {
            $progress(sprintf('%s %s: %d candles (+%d warm-up); %d runs over %s', implode('+', $symbols), $interval, array_sum(array_map('count', $candles)), array_sum(array_map('count', $warmup)), count($combinations), implode(', ', array_map(static fn (string $n): string => "{$n}=" . implode(',', $sweeps[$n]), array_keys($sweeps)))));
        }

        $results = [];
        $skipped = [];

        foreach ($combinations as $index => $combination) {
            $config = Backtest::configure($base, $combination);

            try {
                $engine = Config::engine($config);
            } catch (\InvalidArgumentException $error) {
                // A combination the strategy refuses (fast=30 slow=30, say) is a hole in the grid, not the end of the sweep.
                $skipped[Grid::name($combination, array_keys($sweeps))] = $error->getMessage();

                if ($progress !== null) {
                    $progress(sprintf('%d / %d: %s → skipped: %s', $index + 1, count($combinations), Grid::name($combination, array_keys($sweeps)), $error->getMessage()));
                }

                continue;
            }

            $report = Backtester::runBasket($engine, $warmup, $candles);
            $results[] = ['overrides' => $combination, 'report' => $report];

            if ($progress !== null) {
                $progress(sprintf('%d / %d: %s → %s%.2f%%', $index + 1, count($combinations), Grid::name($combination, array_keys($sweeps)), $report->returnPct >= 0 ? '+' : '', $report->returnPct * 100));
            }
        }

        if ($results === []) {
            throw new \InvalidArgumentException('Every combination was refused: ' . implode('; ', array_unique($skipped)));
        }

        return ['results' => $results, 'sweeps' => $sweeps, 'skipped' => $skipped, 'file' => self::save($results, $sweeps, $skipped)];
    }

    /**
     * The figures the model reads: one row per combination.
     *
     * @param list<array{overrides: array<string, int|float>, report: Report}> $results
     * @return list<array<string, mixed>>
     */
    public static function rows(array $results): array
    {
        return array_map(static fn (array $r): array => $r['overrides'] + [
            'returnPct' => $r['report']->returnPct,
            'buyAndHoldPct' => $r['report']->buyAndHoldPct,
            'maxDrawdownPct' => $r['report']->maxDrawdownPct,
            'profitFactor' => is_finite($r['report']->profitFactor) ? $r['report']->profitFactor : null,
            'closedTrades' => $r['report']->closedTrades,
            'winRate' => $r['report']->winRate,
            'feesPct' => $r['report']->feesPct,
        ], $results);
    }

    /**
     * @param list<array{overrides: array<string, int|float>, report: Report}> $results
     * @param array<string, list<int|float>> $sweeps
     * @param array<string, string> $skipped combination → why the strategy refused it
     */
    private static function save(array $results, array $sweeps, array $skipped): string
    {
        $directory = self::directory();

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        $first = $results[0]['report'];
        $ranges = implode('-', array_map(static fn (string $n): string => $n . $sweeps[$n][0] . '..' . $sweeps[$n][count($sweeps[$n]) - 1], array_keys($sweeps)));
        $file = sprintf('%s/%s-%s-%s-%s-%s-%s.json', $directory, $first->symbol, $first->interval, gmdate('Ymd', intdiv($first->from, 1000)), gmdate('Ymd', intdiv($first->to, 1000)), $first->strategy, $ranges);
        $out = [
            'symbol' => $first->symbol,
            'interval' => $first->interval,
            'strategy' => $first->strategy,
            'from' => $first->from,
            'to' => $first->to,
            'candles' => $first->candles,
            'buyAndHoldPct' => $first->buyAndHoldPct,
            'buyAndHoldDrawdownPct' => $first->buyAndHoldDrawdownPct,
            'sweeps' => $sweeps,
            'skipped' => $skipped,
            'runs' => self::rows($results),
        ];

        if (file_put_contents($file, json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n") === false) {
            throw new \RuntimeException("Could not write {$file}.");
        }

        return $file;
    }
}
