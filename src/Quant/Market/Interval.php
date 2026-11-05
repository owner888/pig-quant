<?php

declare(strict_types=1);

namespace Quant\Market;

/** A candle interval's length, from its name: `1m`, `15m`, `1h`, `1d`, `1w`, `1M`. */
final class Interval
{
    private const array UNITS = ['s' => 1_000, 'm' => 60_000, 'h' => 3_600_000, 'd' => 86_400_000, 'w' => 604_800_000, 'M' => 2_592_000_000];

    /** Milliseconds; a month counts as 30 days, which is what a warm-up window needs it for. */
    public static function milliseconds(string $interval): int
    {
        if (preg_match('/^(\d+)([smhdwM])$/', $interval, $m) !== 1) {
            throw new \InvalidArgumentException("'{$interval}' is not an interval such as 1m, 4h or 1d.");
        }

        return (int) $m[1] * self::UNITS[$m[2]];
    }
}
