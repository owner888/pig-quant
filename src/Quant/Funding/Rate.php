<?php

declare(strict_types=1);

namespace Quant\Funding;

/** One funding settlement of a perpetual swap: when, and the rate longs paid shorts (negative: shorts paid longs). */
final readonly class Rate
{
    public function __construct(
        public int $time,
        public float $rate,
    ) {
    }
}
