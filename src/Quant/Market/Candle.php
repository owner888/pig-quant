<?php

declare(strict_types=1);

namespace Quant\Market;

/** One candle (kline). Times are Unix milliseconds. `closed` is false while the candle is still forming. */
final readonly class Candle
{
    public function __construct(
        public int $openTime,
        public float $open,
        public float $high,
        public float $low,
        public float $close,
        public float $volume,
        public int $closeTime,
        public bool $closed = true,
    ) {
    }

    /** @return array{openTime: int, open: float, high: float, low: float, close: float, volume: float, closeTime: int, closed: bool} */
    public function toArray(): array
    {
        return [
            'openTime' => $this->openTime,
            'open' => $this->open,
            'high' => $this->high,
            'low' => $this->low,
            'close' => $this->close,
            'volume' => $this->volume,
            'closeTime' => $this->closeTime,
            'closed' => $this->closed,
        ];
    }
}
