<?php

declare(strict_types=1);

namespace Quant\Spread;

/**
 * One minute (one candle) of the price gap between two exchanges for one symbol.
 *
 * `bps` is `(b - a) / mid × 10,000`: positive when the second exchange is dearer. `maxAbsBps` is
 * the widest gap seen inside the minute from the forming candles, which is what an arbitrage
 * would have had to catch; `bps` is the gap at the close, which is what a slower one gets.
 */
final readonly class Sample
{
    public function __construct(
        public int $time,
        public float $a,
        public float $b,
        public float $bps,
        public float $maxAbsBps,
    ) {
    }

    public static function bps(float $a, float $b): float
    {
        $mid = ($a + $b) / 2;

        return $mid > 0 ? ($b - $a) / $mid * 10_000 : 0.0;
    }

    /** @return array{time: int, a: float, b: float, bps: float, maxAbsBps: float} */
    public function toArray(): array
    {
        return ['time' => $this->time, 'a' => $this->a, 'b' => $this->b, 'bps' => $this->bps, 'maxAbsBps' => $this->maxAbsBps];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((int) $data['time'], (float) $data['a'], (float) $data['b'], (float) $data['bps'], (float) $data['maxAbsBps']);
    }
}
