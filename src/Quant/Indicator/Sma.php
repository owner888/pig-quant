<?php

declare(strict_types=1);

namespace Quant\Indicator;

/** Simple moving average. */
final class Sma
{
    /**
     * The average of the last `$period` values ending `$back` values before the end; null when
     * there are not enough values.
     *
     * @param list<float> $values oldest first
     */
    public static function at(array $values, int $period, int $back = 0): ?float
    {
        if ($period < 1) {
            throw new \InvalidArgumentException("An SMA period is at least 1, not {$period}.");
        }

        $end = count($values) - $back;

        if ($end < $period) {
            return null;
        }

        return array_sum(array_slice($values, $end - $period, $period)) / $period;
    }
}
