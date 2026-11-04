<?php

declare(strict_types=1);

namespace Quant\Risk;

use Quant\Wallet\DryRunWallet;
use Quant\Wallet\Position;

/**
 * The limits every order passes through, strategy or person: how much one order may spend, how
 * much may be in positions at once, how many positions, and the stop-loss that closes a position
 * without asking the strategy.
 */
final readonly class RiskRules
{
    /**
     * @param float $maxOrderQuote the most one buy spends
     * @param float $maxExposureQuote the most that may be in positions at cost, after the buy
     * @param int $maxPositions how many symbols may be held at once
     * @param float $stopLossPct 0.02 closes a position 2% under its average price; 0 means no stop-loss
     */
    public function __construct(
        public float $maxOrderQuote,
        public float $maxExposureQuote,
        public int $maxPositions,
        public float $stopLossPct,
    ) {
        if ($maxOrderQuote <= 0 || $maxExposureQuote <= 0 || $maxPositions < 1) {
            throw new \InvalidArgumentException('Risk limits are positive.');
        }

        if ($stopLossPct < 0 || $stopLossPct >= 1) {
            throw new \InvalidArgumentException('stopLossPct is a fraction in [0, 1).');
        }
    }

    /** Why a buy of `$quoteAmount` on `$symbol` is refused, or null when it is allowed. */
    public function refuseBuy(string $symbol, float $quoteAmount, DryRunWallet $wallet): ?string
    {
        if ($quoteAmount > $this->maxOrderQuote) {
            return sprintf('order %.2f is over the %.2f per-order limit', $quoteAmount, $this->maxOrderQuote);
        }

        if ($wallet->exposure() + $quoteAmount > $this->maxExposureQuote) {
            return sprintf('exposure would be %.2f, over the %.2f limit', $wallet->exposure() + $quoteAmount, $this->maxExposureQuote);
        }

        if ($wallet->position($symbol) === null && count($wallet->positions()) >= $this->maxPositions) {
            return "already holding {$this->maxPositions} position(s)";
        }

        if ($quoteAmount > $wallet->quote()) {
            return sprintf('only %.2f %s left', $wallet->quote(), $wallet->quoteAsset);
        }

        return null;
    }

    public function stopLossHit(Position $position, float $price): bool
    {
        return $this->stopLossPct > 0 && $price <= $position->averagePrice * (1 - $this->stopLossPct);
    }

    /** @return array<string, float|int> */
    public function toArray(): array
    {
        return [
            'maxOrderQuote' => $this->maxOrderQuote,
            'maxExposureQuote' => $this->maxExposureQuote,
            'maxPositions' => $this->maxPositions,
            'stopLossPct' => $this->stopLossPct,
        ];
    }
}
