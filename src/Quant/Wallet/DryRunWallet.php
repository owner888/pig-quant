<?php

declare(strict_types=1);

namespace Quant\Wallet;

use Quant\Market\Side;

/**
 * A paper wallet: one quote currency (USDT, say) and long spot positions, filled at the price
 * given plus slippage, with a taker fee taken from the quote side. No exchange is touched.
 *
 * Fills are immediate and whole: a dry run tests the strategy's decisions, not the order book.
 */
final class DryRunWallet
{
    /** @var array<string, Position> */
    private array $positions = [];

    /** @var list<Trade> */
    private array $trades = [];

    private float $quote;

    private float $realizedPnl = 0.0;

    public function __construct(
        public readonly string $quoteAsset,
        public readonly float $initialQuote,
        public readonly float $feeRate,
        public readonly float $slippage,
    ) {
        if ($initialQuote <= 0) {
            throw new WalletError("The initial balance is positive, not {$initialQuote}.");
        }

        if ($feeRate < 0 || $feeRate >= 1 || $slippage < 0 || $slippage >= 1) {
            throw new WalletError('Fee rate and slippage are fractions in [0, 1).');
        }

        $this->quote = $initialQuote;
    }

    /** Spend `$quoteAmount` of quote on `$symbol` at `$price` (before slippage and fee). */
    public function buy(string $symbol, float $quoteAmount, float $price, int $time, string $reason): Trade
    {
        self::checkPrice($price);

        if ($quoteAmount <= 0) {
            throw new WalletError("A buy spends a positive amount, not {$quoteAmount}.");
        }

        if ($quoteAmount > $this->quote) {
            throw new WalletError(sprintf('Insufficient %s: %.2f asked, %.2f held.', $this->quoteAsset, $quoteAmount, $this->quote));
        }

        $fill = $price * (1 + $this->slippage);
        $fee = $quoteAmount * $this->feeRate;
        $quantity = ($quoteAmount - $fee) / $fill;

        $this->quote -= $quoteAmount;
        $held = $this->positions[$symbol] ?? null;

        if ($held === null) {
            $this->positions[$symbol] = new Position($symbol, $quantity, $fill, $time);
        } else {
            $total = $held->quantity + $quantity;
            $average = ($held->cost() + $quantity * $fill) / $total;
            $this->positions[$symbol] = new Position($symbol, $total, $average, $held->openedAt);
        }

        return $this->record(new Trade($time, $symbol, Side::Buy, $quantity, $fill, $fee, $quoteAmount, null, $reason));
    }

    /** Sell `$quantity` of the base (null: the whole position) at `$price` (before slippage and fee). */
    public function sell(string $symbol, ?float $quantity, float $price, int $time, string $reason): Trade
    {
        self::checkPrice($price);
        $held = $this->positions[$symbol] ?? throw new WalletError("No {$symbol} position to sell.");
        $quantity ??= $held->quantity;

        if ($quantity <= 0) {
            throw new WalletError("A sell is for a positive quantity, not {$quantity}.");
        }

        if ($quantity > $held->quantity * (1 + 1e-9)) {
            throw new WalletError(sprintf('Only %s %s held, %s asked.', self::number($held->quantity), $symbol, self::number($quantity)));
        }

        $quantity = min($quantity, $held->quantity);
        $fill = $price * (1 - $this->slippage);
        $gross = $quantity * $fill;
        $fee = $gross * $this->feeRate;
        $proceeds = $gross - $fee;
        $pnl = $proceeds - $quantity * $held->averagePrice;

        $this->quote += $proceeds;
        $this->realizedPnl += $pnl;
        $rest = $held->quantity - $quantity;

        if ($rest <= 1e-12) {
            unset($this->positions[$symbol]);
        } else {
            $this->positions[$symbol] = new Position($symbol, $rest, $held->averagePrice, $held->openedAt);
        }

        return $this->record(new Trade($time, $symbol, Side::Sell, $quantity, $fill, $fee, $proceeds, $pnl, $reason));
    }

    public function quote(): float
    {
        return $this->quote;
    }

    public function realizedPnl(): float
    {
        return $this->realizedPnl;
    }

    public function position(string $symbol): ?Position
    {
        return $this->positions[$symbol] ?? null;
    }

    /** @return array<string, Position> by symbol */
    public function positions(): array
    {
        return $this->positions;
    }

    /** @return list<Trade> oldest first */
    public function trades(): array
    {
        return $this->trades;
    }

    /** Quote held plus every position at the price given for it. @param array<string, float> $prices */
    public function equity(array $prices): float
    {
        $equity = $this->quote;

        foreach ($this->positions as $symbol => $position) {
            $price = $prices[$symbol] ?? throw new WalletError("No price for {$symbol} to value the position.");
            $equity += $position->quantity * $price;
        }

        return $equity;
    }

    /** Quote currently in positions, at cost. */
    public function exposure(): float
    {
        return array_sum(array_map(static fn (Position $p): float => $p->cost(), $this->positions));
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'quoteAsset' => $this->quoteAsset,
            'initialQuote' => $this->initialQuote,
            'feeRate' => $this->feeRate,
            'slippage' => $this->slippage,
            'quote' => $this->quote,
            'realizedPnl' => $this->realizedPnl,
            'positions' => array_values(array_map(static fn (Position $p): array => $p->toArray(), $this->positions)),
            'trades' => array_map(static fn (Trade $t): array => $t->toArray(), $this->trades),
        ];
    }

    /**
     * Restore balances, positions and the trade log saved by `toArray()` — into a wallet built
     * with the same quote asset; the configured fee and slippage stay the wallet's own.
     *
     * @param array<string, mixed> $data
     */
    public function restore(array $data): void
    {
        if (($data['quoteAsset'] ?? null) !== $this->quoteAsset) {
            throw new WalletError(sprintf('Saved state is in %s, this wallet in %s.', $data['quoteAsset'] ?? '?', $this->quoteAsset));
        }

        $this->quote = (float) ($data['quote'] ?? $this->initialQuote);
        $this->realizedPnl = (float) ($data['realizedPnl'] ?? 0.0);
        $this->positions = [];
        $this->trades = [];

        foreach ((array) ($data['positions'] ?? []) as $p) {
            $this->positions[(string) $p['symbol']] = new Position((string) $p['symbol'], (float) $p['quantity'], (float) $p['averagePrice'], (int) ($p['openedAt'] ?? 0));
        }

        foreach ((array) ($data['trades'] ?? []) as $t) {
            $this->trades[] = Trade::fromArray($t);
        }
    }

    private function record(Trade $trade): Trade
    {
        $this->trades[] = $trade;

        return $trade;
    }

    private static function checkPrice(float $price): void
    {
        if ($price <= 0 || !is_finite($price)) {
            throw new WalletError("A fill price is positive and finite, not {$price}.");
        }
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8f', $value), '0'), '.');
    }
}
