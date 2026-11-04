<?php

declare(strict_types=1);

namespace Quant\Strategy;

use Quant\Indicator\Sma;
use Quant\Market\Series;
use Quant\Market\Side;
use Quant\Wallet\Position;

/**
 * Buy when the fast SMA crosses above the slow one on the candle that just closed; sell when it
 * crosses below. The example strategy: simple enough to check by hand against a chart.
 */
final class MovingAverageCross implements Strategy
{
    public function __construct(
        private readonly int $fast = 9,
        private readonly int $slow = 21,
    ) {
        if ($fast < 1 || $slow <= $fast) {
            throw new \InvalidArgumentException("The fast period ({$fast}) is at least 1 and shorter than the slow one ({$slow}).");
        }
    }

    public function name(): string
    {
        return 'ma-cross';
    }

    public function warmup(): int
    {
        return $this->slow + 1;
    }

    public function parameters(): array
    {
        return ['fast' => $this->fast, 'slow' => $this->slow];
    }

    public function onCandle(Series $series, ?Position $position): ?Side
    {
        $closes = $series->closes();
        $fastNow = Sma::at($closes, $this->fast);
        $slowNow = Sma::at($closes, $this->slow);
        $fastBefore = Sma::at($closes, $this->fast, 1);
        $slowBefore = Sma::at($closes, $this->slow, 1);

        if ($fastNow === null || $slowNow === null || $fastBefore === null || $slowBefore === null) {
            return null;
        }

        if ($fastBefore <= $slowBefore && $fastNow > $slowNow) {
            return $position === null ? Side::Buy : null;
        }

        if ($fastBefore >= $slowBefore && $fastNow < $slowNow) {
            return $position !== null ? Side::Sell : null;
        }

        return null;
    }
}
