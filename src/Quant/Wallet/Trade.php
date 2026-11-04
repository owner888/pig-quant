<?php

declare(strict_types=1);

namespace Quant\Wallet;

use Quant\Market\Side;

/** One fill. `pnl` is the realized profit of a sell against the position's average price; null for a buy. */
final readonly class Trade
{
    public function __construct(
        public int $time,
        public string $symbol,
        public Side $side,
        public float $quantity,
        public float $price,
        public float $fee,
        public float $quoteAmount,
        public ?float $pnl,
        public string $reason,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'time' => $this->time,
            'symbol' => $this->symbol,
            'side' => $this->side->value,
            'quantity' => $this->quantity,
            'price' => $this->price,
            'fee' => $this->fee,
            'quoteAmount' => $this->quoteAmount,
            'pnl' => $this->pnl,
            'reason' => $this->reason,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self(
            time: (int) $data['time'],
            symbol: (string) $data['symbol'],
            side: Side::from((string) $data['side']),
            quantity: (float) $data['quantity'],
            price: (float) $data['price'],
            fee: (float) $data['fee'],
            quoteAmount: (float) $data['quoteAmount'],
            pnl: isset($data['pnl']) ? (float) $data['pnl'] : null,
            reason: (string) ($data['reason'] ?? ''),
        );
    }
}
