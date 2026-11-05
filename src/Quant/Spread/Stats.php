<?php

declare(strict_types=1);

namespace Quant\Spread;

/**
 * What a run of samples says about the gap: how wide it usually is, how often it is wider than
 * what a round trip costs, and how long such a stretch lasts — the three numbers that decide
 * whether an arbitrage between the two has anything in it.
 */
final class Stats
{
    /**
     * @param list<Sample> $samples oldest first
     * @param float $thresholdBps the gap a round trip has to clear: both sides' fees and slippage
     * @return array<string, mixed>
     */
    public static function summarize(array $samples, float $thresholdBps): array
    {
        $count = count($samples);

        if ($count === 0) {
            return ['samples' => 0, 'thresholdBps' => $thresholdBps];
        }

        $bps = array_map(static fn (Sample $s): float => $s->bps, $samples);
        $abs = array_map('abs', $bps);
        $max = array_map(static fn (Sample $s): float => $s->maxAbsBps, $samples);
        sort($abs);
        sort($max);

        // Stretches of consecutive minutes whose close gap clears the threshold, by direction.
        $runs = [];
        $run = 0;
        $direction = 0;

        foreach ($bps as $value) {
            $now = abs($value) >= $thresholdBps ? ($value > 0 ? 1 : -1) : 0;

            if ($now !== 0 && $now === $direction) {
                $run++;
            } else {
                if ($run > 0) {
                    $runs[] = $run;
                }

                $run = $now === 0 ? 0 : 1;
                $direction = $now;
            }
        }

        if ($run > 0) {
            $runs[] = $run;
        }

        $aboveClose = count(array_filter($abs, static fn (float $v): bool => $v >= $thresholdBps));
        $aboveMax = count(array_filter($max, static fn (float $v): bool => $v >= $thresholdBps));

        return [
            'samples' => $count,
            'from' => $samples[0]->time,
            'to' => $samples[$count - 1]->time,
            'thresholdBps' => $thresholdBps,
            'meanBps' => array_sum($bps) / $count,
            'meanAbsBps' => array_sum($abs) / $count,
            'p50AbsBps' => self::percentile($abs, 0.5),
            'p90AbsBps' => self::percentile($abs, 0.9),
            'p99AbsBps' => self::percentile($abs, 0.99),
            'maxAbsBps' => $abs[$count - 1],
            'p90IntraMinuteBps' => self::percentile($max, 0.9),
            'maxIntraMinuteBps' => $max[$count - 1],
            'minutesAboveThreshold' => $aboveClose,
            'shareAboveThreshold' => $aboveClose / $count,
            'minutesAboveThresholdIntraMinute' => $aboveMax,
            'runsAboveThreshold' => count($runs),
            'longestRunMinutes' => $runs === [] ? 0 : max($runs),
            'bSideDearerShare' => count(array_filter($bps, static fn (float $v): bool => $v > 0)) / $count,
        ];
    }

    /** @param array<string, mixed> $stats @return list<string> */
    public static function lines(array $stats, string $a, string $b, string $symbol): array
    {
        if ($stats['samples'] === 0) {
            return ["{$symbol} {$a} vs {$b}: no complete minutes yet."];
        }

        return [
            sprintf('%s · %s vs %s · %d minutes · %s → %s', $symbol, $a, $b, $stats['samples'], gmdate('m-d H:i', intdiv($stats['from'], 1000)), gmdate('m-d H:i', intdiv($stats['to'], 1000))),
            sprintf('Gap at the close: mean %.2f bps (%s dearer %.0f%% of the time), |gap| p50 %.2f · p90 %.2f · p99 %.2f · max %.2f bps', $stats['meanBps'], $b, $stats['bSideDearerShare'] * 100, $stats['p50AbsBps'], $stats['p90AbsBps'], $stats['p99AbsBps'], $stats['maxAbsBps']),
            sprintf('Widest within a minute: p90 %.2f · max %.2f bps', $stats['p90IntraMinuteBps'], $stats['maxIntraMinuteBps']),
            sprintf('Over the %.0f bps a round trip costs: %d minutes at the close (%.2f%%), %d minutes at some point inside; %d stretches, the longest %d minutes', $stats['thresholdBps'], $stats['minutesAboveThreshold'], $stats['shareAboveThreshold'] * 100, $stats['minutesAboveThresholdIntraMinute'], $stats['runsAboveThreshold'], $stats['longestRunMinutes']),
        ];
    }

    /** @param list<float> $sorted ascending */
    private static function percentile(array $sorted, float $p): float
    {
        $index = (int) floor($p * (count($sorted) - 1));

        return $sorted[$index];
    }
}
