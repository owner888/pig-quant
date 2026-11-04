<?php

declare(strict_types=1);

namespace Quant\Exchange;

use Quant\Market\Candle;

/** One candle of one symbol, as a stream sent it. */
final readonly class StreamUpdate
{
    public function __construct(
        public string $symbol,
        public string $interval,
        public Candle $candle,
    ) {
    }
}
