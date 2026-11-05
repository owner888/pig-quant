<?php

declare(strict_types=1);

namespace PigQuant;

use Closure;
use Pig\CodingAgent\Config as PigConfig;
use Pig\CodingAgent\Settings;
use Quant\Backtest\Backtester;
use Quant\Backtest\Report;
use Quant\Market\Candle;
use Quant\Market\Interval;
use Quant\Risk\Limit;

/**
 * A backtest as `/quant backtest` and `quant_backtest` ask for it: a symbol (or a basket of
 * them, comma-separated), an interval, a range, and the settings' `quant` block with any of its numbers overridden for this run
 * (`fast=5 slow=20 stopLossPct=0.03`), so parameters are compared without editing settings.
 *
 * The engine is a fresh one — the dry run's wallet is not touched — and the report is kept as
 * JSON under `~/.pig/agent/quant/backtests/`, named by what it tested, for comparing runs later.
 */
final class Backtest
{
    /** The settings a run may override, by the name given on the command line. */
    public const array OVERRIDES = ['initialQuote', 'feeRate', 'slippage', 'orderQuote', 'fast', 'slow', 'maxOrderQuote', 'maxExposureQuote', 'maxPositions', 'stopLossPct', 'warmupCandles'];

    public static function directory(): string
    {
        return PigConfig::home() . '/quant/backtests';
    }

    /**
     * @param array<string, string|float|int> $overrides by `OVERRIDES` name; `orderQuote`, `maxOrderQuote` and `maxExposureQuote` may be `"50%"` or `"all"`
     * @param Closure(string): void|null $progress a line at a time
     * @return array{report: Report, file: string}
     */
    public static function run(?Settings $settings, string $symbol, string $interval, int $from, int $to, array $overrides = [], ?Closure $progress = null): array
    {
        $config = self::configure(Config::load($settings), $overrides);
        $config['symbols'] = self::symbols($symbol);
        $config['interval'] = $interval;
        $engine = Config::engine($config);
        ['symbols' => $symbols, 'warmup' => $warmup, 'candles' => $candles] = self::candles($config, $symbol, $interval, $from, $to, $progress);

        if ($progress !== null) {
            $progress(sprintf('%s %s: %d candles (+%d warm-up); running %s %s', implode('+', $symbols), $interval, array_sum(array_map('count', $candles)), array_sum(array_map('count', $warmup)), $engine->strategy->name(), json_encode($engine->strategy->parameters())));
        }

        $report = Backtester::runBasket($engine, $warmup, $candles, static function (int $done, int $total) use ($progress): void {
            if ($done === $total || $done % 10_000 === 0) {
                if ($progress !== null) {
                    $progress("{$done} / {$total} candles");
                }
            }
        });

        return ['report' => $report, 'file' => self::save($report)];
    }

    /**
     * `BTC-USDT,ETH-USDT` → the symbols of a basket (one symbol is a basket of one).
     *
     * @return list<string>
     */
    public static function symbols(string $text): array
    {
        $symbols = array_values(array_filter(array_map('trim', explode(',', $text)), static fn (string $s): bool => $s !== ''));

        if ($symbols === []) {
            throw new \InvalidArgumentException('A backtest needs a symbol.');
        }

        return $symbols;
    }

    /**
     * The warm-up and the range for a run, every symbol of the basket, from the exchange the
     * settings name, through the monthly cache. Shared by a backtest and by every run of a sweep.
     *
     * @param array<string, mixed> $config
     * @param string $symbol one symbol, or several comma-separated
     * @param Closure(string): void|null $progress
     * @return array{symbols: list<string>, warmup: array<string, list<Candle>>, candles: array<string, list<Candle>>}
     */
    public static function candles(array $config, string $symbol, string $interval, int $from, int $to, ?Closure $progress = null): array
    {
        if ($to <= $from) {
            throw new \InvalidArgumentException('The range ends before it starts.');
        }

        $exchange = Config::exchange($config);
        $history = new History($exchange);
        $warmupFrom = $from - (int) $config['warmupCandles'] * Interval::milliseconds($interval);
        $symbols = [];
        $warmup = [];
        $candles = [];

        foreach (self::symbols($symbol) as $one) {
            $one = $exchange->symbol($one);
            $symbols[] = $one;
            $warmup[$one] = $history->range($one, $interval, $warmupFrom, $from, $progress);
            $candles[$one] = $history->range($one, $interval, $from, $to, $progress);

            if ($candles[$one] === []) {
                throw new \RuntimeException("{$exchange->name()} has no {$one} {$interval} candles between " . gmdate('Y-m-d', intdiv($from, 1000)) . ' and ' . gmdate('Y-m-d', intdiv($to, 1000)) . '.');
            }
        }

        return ['symbols' => $symbols, 'warmup' => $warmup, 'candles' => $candles];
    }

    /**
     * The settings with the overrides applied, each parsed as the number it is.
     *
     * @param array<string, mixed> $config
     * @param array<string, string|float|int> $overrides
     * @return array<string, mixed>
     */
    public static function configure(array $config, array $overrides): array
    {
        foreach ($overrides as $name => $value) {
            if (!in_array($name, self::OVERRIDES, true)) {
                throw new \InvalidArgumentException("'{$name}' is not a setting a backtest can override; there is: " . implode(', ', self::OVERRIDES) . '.');
            }

            // The order size and the two amount limits may be a share: "50%" or "all".
            if (in_array($name, ['orderQuote', 'maxOrderQuote', 'maxExposureQuote'], true) && Limit::isShare($value)) {
                $parsed = Limit::parse((string) $value)->value();

                if ($name === 'orderQuote') {
                    $config[$name] = $parsed;
                } else {
                    $config['risk'][$name] = $parsed;
                }

                continue;
            }

            if (!is_numeric($value)) {
                throw new \InvalidArgumentException("{$name} is a number, not '{$value}'.");
            }

            $number = $value + 0;

            match ($name) {
                'fast', 'slow' => $config['strategy'][$name] = (int) $number,
                'maxOrderQuote', 'maxExposureQuote', 'stopLossPct' => $config['risk'][$name] = (float) $number,
                'maxPositions' => $config['risk'][$name] = (int) $number,
                'warmupCandles' => $config[$name] = (int) $number,
                default => $config[$name] = (float) $number,
            };
        }

        return $config;
    }

    /**
     * `<symbol[,symbol…]> <interval> <from> [to] [name=value…]` into its parts. Dates are `YYYY-MM-DD`
     * (UTC) or `YYYY-MM-DDTHH:MM`; `to` defaults to now.
     *
     * @return array{symbol: string, interval: string, from: int, to: int, overrides: array<string, string>}
     */
    public static function parseArgs(string $args, string $command = 'backtest'): array
    {
        $parts = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $positional = [];
        $overrides = [];

        foreach ($parts as $part) {
            if (str_contains($part, '=')) {
                [$name, $value] = explode('=', $part, 2);
                $overrides[$name] = $value;
            } else {
                $positional[] = $part;
            }
        }

        if (count($positional) < 3) {
            throw new \InvalidArgumentException($command === 'sweep'
                ? 'Usage: /quant sweep <symbol> <interval> <from> [to] fast=10..30:5 slow=30..100:10 [stopLossPct=0.1 …]  — e.g. /quant sweep BTCUSDT 1d 2024-11-01 2026-11-01 fast=10..30:5 slow=30..100:10'
                : 'Usage: /quant backtest <symbol> <interval> <from> [to] [fast=9 slow=21 …]  — e.g. /quant backtest BTCUSDT 1m 2026-10-01 2026-11-01');
        }

        return [
            'symbol' => $positional[0],
            'interval' => $positional[1],
            'from' => self::parseDate($positional[2]),
            'to' => isset($positional[3]) ? self::parseDate($positional[3]) : (int) floor(microtime(true) * 1000),
            'overrides' => $overrides,
        ];
    }

    /** `YYYY-MM-DD` or `YYYY-MM-DDTHH:MM`, UTC, to milliseconds. */
    public static function parseDate(string $text): int
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $text, new \DateTimeZone('UTC'))
            ?: \DateTimeImmutable::createFromFormat('!Y-m-d\TH:i', $text, new \DateTimeZone('UTC'));

        if ($date === false) {
            throw new \InvalidArgumentException("'{$text}' is not a date: write 2026-10-01 or 2026-10-01T09:30 (UTC).");
        }

        return $date->getTimestamp() * 1000;
    }

    /** `0.001` → `0.001`, `100.0` → `100`: a number as it reads, for a file name. */
    private static function short(float $value): string
    {
        return rtrim(rtrim(sprintf('%.6f', $value), '0'), '.');
    }

    private static function save(Report $report): string
    {
        $directory = self::directory();

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        // Everything that changes the result is in the name, so two runs never overwrite each other.
        $params = implode('-', array_map(static fn ($k, $v): string => "{$k}{$v}", array_keys($report->parameters), $report->parameters));
        $costs = sprintf('order%s-fee%s-slip%s-stop%s', Limit::parse($report->orderQuote)->label(), self::short($report->feeRate), self::short($report->slippage), self::short((float) $report->risk['stopLossPct']));
        $file = sprintf('%s/%s-%s-%s-%s-%s-%s-%s.json', $directory, $report->symbol, $report->interval, gmdate('Ymd', intdiv($report->from, 1000)), gmdate('Ymd', intdiv($report->to, 1000)), $report->strategy, $params, $costs);

        if (file_put_contents($file, json_encode($report->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION) . "\n") === false) {
            throw new \RuntimeException("Could not write {$file}.");
        }

        return $file;
    }
}
