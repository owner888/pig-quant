<?php

declare(strict_types=1);

namespace Quant\Backtest;

use Quant\Risk\Limit;

/**
 * A parameter sweep: the same backtest over every combination of a few parameter ranges, and
 * the results laid out so one look says whether a good result sits in a region of good ones
 * or alone. Three hand-run points cannot tell an edge from a lucky parameter; a grid can.
 *
 * The pure part lives here — expanding `10..30:5` into values, combining ranges, drawing the
 * table — so the host only fetches candles and runs engines.
 */
final class Sweep
{
    /** More combinations than this is a mistake in the ranges, not a sweep anyone waits for. */
    public const int MAX_RUNS = 500;

    /**
     * `10..30:5` → `[10, 15, 20, 25, 30]`; `10..12` → `[10, 11, 12]`; `5,10,20` → `[5, 10, 20]`;
     * a plain number → `[number]`; `all` or `50%` (an order size or amount limit as a share) → itself.
     * Integers stay integers so `fast=10` reaches the strategy as one.
     *
     * @return list<int|float|string>
     */
    public static function values(string $name, string $spec): array
    {
        if (Limit::isShare($spec)) {
            return [Limit::parse($spec)->value()];
        }

        if (preg_match('/^(-?[\d.]+)\.\.(-?[\d.]+)(?::(-?[\d.]+))?$/', $spec, $m) === 1) {
            $from = self::number($name, $m[1]);
            $to = self::number($name, $m[2]);
            $step = isset($m[3]) ? self::number($name, $m[3]) : 1;

            if ($step == 0 || ($to - $from) * $step < 0) {
                throw new \InvalidArgumentException("{$name}={$spec}: the step must move from the start towards the end.");
            }

            // Count first, then compute each value from the start: adding 0.1 repeatedly drifts.
            $count = (int) floor(abs($to - $from) / abs($step) + 1e-9) + 1;
            $out = [];

            for ($i = 0; $i < $count; $i++) {
                $value = $from + $i * $step;
                $out[] = is_int($from) && is_int($step) ? (int) $value : round((float) $value, 10);
            }

            return $out;
        }

        return array_map(static fn (string $v): int|float => self::number($name, trim($v)), explode(',', $spec));
    }

    /**
     * Every combination of the swept values, in the order given (the first name varies slowest),
     * each with the fixed overrides included.
     *
     * @param array<string, list<int|float>> $sweeps name → values (more than one)
     * @param array<string, int|float|string> $fixed name → value (one)
     * @return list<array<string, int|float|string>>
     */
    public static function combinations(array $sweeps, array $fixed = []): array
    {
        $out = [$fixed];

        foreach ($sweeps as $name => $values) {
            $next = [];

            foreach ($out as $combination) {
                foreach ($values as $value) {
                    $next[] = $combination + [$name => $value];
                }
            }

            $out = $next;
        }

        if (count($out) > self::MAX_RUNS) {
            throw new \InvalidArgumentException(count($out) . ' combinations is more than the ' . self::MAX_RUNS . ' a sweep runs; narrow the ranges or widen the steps.');
        }

        return $out;
    }

    /**
     * Splits `name=value` overrides into the ones that sweep (a range or list) and the ones that
     * do not, parsed.
     *
     * @param array<string, string> $overrides
     * @return array{sweeps: array<string, list<int|float>>, fixed: array<string, int|float|string>}
     */
    public static function split(array $overrides): array
    {
        $sweeps = [];
        $fixed = [];

        foreach ($overrides as $name => $spec) {
            $values = self::values($name, $spec);

            if (count($values) > 1) {
                $sweeps[$name] = $values;
            } else {
                $fixed[$name] = $values[0];
            }
        }

        return ['sweeps' => $sweeps, 'fixed' => $fixed];
    }

    /**
     * The results as text. Two swept parameters make a grid — rows for the first, columns for
     * the second, each cell `return / profit factor`; any other number makes a list sorted by
     * return. Both end with how many combinations beat buy & hold and how many lost money.
     *
     * @param list<array{overrides: array<string, int|float>, report: Report}> $results
     * @param array<string, list<int|float>> $sweeps
     * @return list<string>
     */
    public static function lines(array $results, array $sweeps): array
    {
        if ($results === []) {
            return ['Nothing ran.'];
        }

        $first = $results[0]['report'];
        $pct = static fn (float $v): string => sprintf('%s%.1f%%', $v >= 0 ? '+' : '', $v * 100);
        $pf = static fn (float $v): string => !is_finite($v) ? '∞' : ($v >= 100 ? '99+' : sprintf('%.2f', $v));
        $cell = static fn (Report $r): string => $pct($r->returnPct) . '/' . $pf($r->profitFactor);

        $lines = [sprintf('%s %s · %s · %s → %s · %d runs · buy & hold %s (max drawdown %.1f%%)', $first->symbol, $first->interval, $first->strategy, gmdate('Y-m-d', intdiv($first->from, 1000)), gmdate('Y-m-d', intdiv($first->to, 1000)), count($results), $pct($first->buyAndHoldPct), $first->buyAndHoldDrawdownPct * 100)];

        if (count($sweeps) === 2) {
            [$rowName, $colName] = array_keys($sweeps);
            [$rows, $cols] = array_values($sweeps);
            $byKey = [];

            foreach ($results as $result) {
                $byKey[$result['overrides'][$rowName] . '|' . $result['overrides'][$colName]] = $result['report'];
            }

            $cells = array_map($cell, array_values($byKey));
            $width = max(8, ...array_map(static fn (string $c): int => mb_strlen($c) + 2, [...$cells, ...array_map('strval', $cols)]));
            $label = str_pad("{$rowName}\\{$colName}", 12);
            $lines[] = $label . implode('', array_map(static fn (int|float $c): string => str_pad((string) $c, $width, ' ', STR_PAD_LEFT), $cols));

            foreach ($rows as $row) {
                $line = str_pad((string) $row, 12);

                foreach ($cols as $col) {
                    $report = $byKey["{$row}|{$col}"] ?? null;
                    $text = $report === null ? '—' : $cell($report);
                    $line .= str_repeat(' ', max(0, $width - mb_strlen($text))) . $text;
                }

                $lines[] = $line;
            }

            $lines[] = 'Each cell: return / profit factor.';
        } else {
            $sorted = $results;
            usort($sorted, static fn (array $a, array $b): int => $b['report']->returnPct <=> $a['report']->returnPct);

            foreach ($sorted as $result) {
                $r = $result['report'];
                $lines[] = sprintf('%s → %s · PF %s · drawdown %.1f%% · %d closed', self::name($result['overrides'], array_keys($sweeps)), $pct($r->returnPct), $pf($r->profitFactor), $r->maxDrawdownPct * 100, $r->closedTrades);
            }
        }

        $beat = count(array_filter($results, static fn (array $r): bool => $r['report']->returnPct > $r['report']->buyAndHoldPct));
        $lost = count(array_filter($results, static fn (array $r): bool => $r['report']->returnPct < 0));
        $lines[] = sprintf('%d of %d beat buy & hold; %d lost money.', $beat, count($results), $lost);

        return $lines;
    }

    /**
     * `fast=10 slow=30` — the swept values of one combination, for a line or a file name.
     *
     * @param array<string, int|float> $overrides
     * @param list<string> $names
     */
    public static function name(array $overrides, array $names, string $glue = ' '): string
    {
        return implode($glue, array_map(static fn (string $n): string => "{$n}={$overrides[$n]}", $names));
    }

    private static function number(string $name, string $text): int|float
    {
        if (!is_numeric($text)) {
            throw new \InvalidArgumentException("{$name} is a number, a range (10..30:5) or a list (5,10,20), not '{$text}'.");
        }

        return $text + 0;
    }
}
