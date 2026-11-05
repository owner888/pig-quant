<?php

declare(strict_types=1);

namespace Quant\Funding;

/**
 * What a cash-and-carry would have earned from funding: long the spot, short the perpetual for
 * the same amount, so the price cancels out and what is left is the funding the shorts collect
 * every settlement (and pay, when the rate is negative). The figures are per unit of notional,
 * before and after the four legs a round trip costs, and on the capital both legs tie up.
 *
 * Not modelled: the basis at entry and exit (the perpetual trading above or below the spot),
 * which can add or cost a few tenths of a percent, and the exchange itself failing.
 */
final class Carry
{
    /**
     * @param list<Rate> $rates oldest first
     * @param float $spotFeeRate one spot fill
     * @param float $perpFeeRate one perpetual fill
     * @param float $slippage one fill, either leg
     * @param float $marginRatio collateral behind the short, as a share of its notional (0.5 = 2x leverage)
     * @return array<string, mixed>
     */
    public static function summarize(array $rates, float $spotFeeRate, float $perpFeeRate, float $slippage, float $marginRatio): array
    {
        $count = count($rates);
        $roundTrip = 2 * ($spotFeeRate + $slippage) + 2 * ($perpFeeRate + $slippage);
        $capital = 1 + $marginRatio;

        if ($count === 0) {
            return ['settlements' => 0, 'roundTripPct' => $roundTrip, 'marginRatio' => $marginRatio];
        }

        $gross = 0.0;
        $negative = 0;
        $months = [];
        $longest = 0;
        $run = 0;
        $longestFrom = null;
        $runFrom = null;
        $values = [];

        foreach ($rates as $rate) {
            $gross += $rate->rate;
            $values[] = $rate->rate;
            $month = gmdate('Y-m', intdiv($rate->time, 1000));
            $months[$month] = ($months[$month] ?? 0.0) + $rate->rate;

            if ($rate->rate < 0) {
                $negative++;
                $run++;
                $runFrom ??= $rate->time;

                if ($run > $longest) {
                    $longest = $run;
                    $longestFrom = $runFrom;
                }
            } else {
                $run = 0;
                $runFrom = null;
            }
        }

        sort($values);
        $first = $rates[0]->time;
        $last = $rates[$count - 1]->time;
        $days = max(1.0, ($last - $first) / 86_400_000);
        $years = $days / 365.25;
        $settlementsPerDay = $count / $days;
        $net = $gross - $roundTrip;
        $negativeMonths = count(array_filter($months, static fn (float $m): bool => $m < 0));
        $worstMonth = $months === [] ? null : array_keys($months, min($months), true)[0];
        $bestMonth = $months === [] ? null : array_keys($months, max($months), true)[0];

        return [
            'settlements' => $count,
            'from' => $first,
            'to' => $last,
            'days' => $days,
            'settlementsPerDay' => $settlementsPerDay,
            'grossPct' => $gross,
            'grossAnnualizedPct' => $gross / $years,
            'roundTripPct' => $roundTrip,
            'netPct' => $net,
            'netAnnualizedPct' => $net / $years,
            'marginRatio' => $marginRatio,
            'onCapitalAnnualizedPct' => $net / $years / $capital,
            'meanRate' => $gross / $count,
            'medianRate' => $values[intdiv($count, 2)],
            'p10Rate' => $values[(int) floor(($count - 1) * 0.1)],
            'p90Rate' => $values[(int) floor(($count - 1) * 0.9)],
            'negativeSettlements' => $negative,
            'negativeShare' => $negative / $count,
            'longestNegativeSettlements' => $longest,
            'longestNegativeDays' => $longest / max($settlementsPerDay, 1e-9),
            'longestNegativeFrom' => $longestFrom,
            'months' => $months,
            'negativeMonths' => $negativeMonths,
            'bestMonth' => $bestMonth,
            'worstMonth' => $worstMonth,
        ];
    }

    /**
     * The summary as a person reads it.
     *
     * @param array<string, mixed> $s from `summarize()`
     * @return list<string>
     */
    public static function lines(array $s, string $symbol): array
    {
        if (($s['settlements'] ?? 0) === 0) {
            return ["{$symbol}: no funding settlements in the range."];
        }

        $pct = static fn (float $v, int $d = 2): string => sprintf('%s%.' . $d . 'f%%', $v >= 0 ? '+' : '', $v * 100);
        $lines = [
            sprintf('%s · %d settlements (%.0f a day) · %s → %s · %.0f days', $symbol, $s['settlements'], $s['settlementsPerDay'], gmdate('Y-m-d', intdiv($s['from'], 1000)), gmdate('Y-m-d', intdiv($s['to'], 1000)), $s['days']),
            sprintf('Funding collected: %s gross, %s a year · median rate %s per settlement (p10 %s, p90 %s)', $pct($s['grossPct']), $pct($s['grossAnnualizedPct'], 1), $pct($s['medianRate'], 4), $pct($s['p10Rate'], 4), $pct($s['p90Rate'], 4)),
            sprintf('After one round trip (%s, four legs): %s, %s a year · on capital with %.0f%% margin behind the short: %s a year', $pct($s['roundTripPct']), $pct($s['netPct']), $pct($s['netAnnualizedPct'], 1), $s['marginRatio'] * 100, $pct($s['onCapitalAnnualizedPct'], 1)),
            sprintf('Negative: %d settlements (%.0f%%); longest stretch %d settlements ≈ %.1f days%s', $s['negativeSettlements'], $s['negativeShare'] * 100, $s['longestNegativeSettlements'], $s['longestNegativeDays'], $s['longestNegativeFrom'] !== null ? ' from ' . gmdate('Y-m-d', intdiv($s['longestNegativeFrom'], 1000)) : ''),
            sprintf('Months: %d negative of %d · best %s %s · worst %s %s', $s['negativeMonths'], count($s['months']), $s['bestMonth'], $pct($s['months'][$s['bestMonth']]), $s['worstMonth'], $pct($s['months'][$s['worstMonth']])),
        ];

        // The months, four to a line, so the shape of the two years is one glance.
        $cells = [];

        foreach ($s['months'] as $month => $value) {
            $cells[] = sprintf('%s %s', $month, str_pad($pct($value), 7, ' ', STR_PAD_LEFT));
        }

        foreach (array_chunk($cells, 4) as $chunk) {
            $lines[] = '  ' . implode('   ', $chunk);
        }

        return $lines;
    }
}
