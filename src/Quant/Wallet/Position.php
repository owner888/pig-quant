<?php

declare(strict_types=1);

namespace Quant\Wallet;

/** What the wallet holds of one symbol: the base quantity and the average price paid for it. */
final readonly class Position
{
    public function __construct(
        public string $symbol,
        public float $quantity,
        public float $averagePrice,
        public int $openedAt,
    ) {
    }

    public function cost(): float
    {
        return $this->quantity * $this->averagePrice;
    }

    public function unrealizedPnl(float $price): float
    {
        return ($price - $this->averagePrice) * $this->quantity;
    }

    /** @return array{symbol: string, quantity: float, averagePrice: float, openedAt: int} */
    public function toArray(): array
    {
        return ['symbol' => $this->symbol, 'quantity' => $this->quantity, 'averagePrice' => $this->averagePrice, 'openedAt' => $this->openedAt];
    }
}
