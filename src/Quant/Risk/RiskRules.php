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
final class RiskRules
{
    public Limit $maxOrderQuote;
    public Limit $maxExposureQuote;

    /**
     * @param Limit|float|string $maxOrderQuote the most one buy spends: an amount, or a share of the equity (`"50%"`, `"all"`)
     * @param Limit|float|string $maxExposureQuote the most that may be in positions at cost, after the buy: an amount or a share of the equity
     * @param int $maxPositions how many symbols may be held at once
     * @param float $stopLossPct 0.02 closes a position 2% under its average price; 0 means no stop-loss
     */
    public function __construct(
        Limit|float|string $maxOrderQuote,
        Limit|float|string $maxExposureQuote,
        public int $maxPositions,
        public float $stopLossPct,
    ) {
        $this->maxOrderQuote = Limit::parse($maxOrderQuote);
        $this->maxExposureQuote = Limit::parse($maxExposureQuote);

        if ($maxPositions < 1) {
            throw new \InvalidArgumentException('maxPositions is at least 1.');
        }

        if ($stopLossPct < 0 || $stopLossPct >= 1) {
            throw new \InvalidArgumentException('stopLossPct is a fraction in [0, 1).');
        }
    }

    /**
     * Why a buy of `$quoteAmount` on `$symbol` is refused, or null when it is allowed.
     *
     * @param float $equity cash plus positions at the last prices — what a share limit is a share of
     */
    public function refuseBuy(string $symbol, float $quoteAmount, DryRunWallet $wallet, float $equity): ?string
    {
        $maxOrder = $this->maxOrderQuote->quote($equity);
        $maxExposure = $this->maxExposureQuote->quote($equity);

        if ($quoteAmount > $maxOrder + 1e-9) {
            return sprintf('order %.2f is over the %.2f per-order limit', $quoteAmount, $maxOrder);
        }

        if ($wallet->exposure() + $quoteAmount > $maxExposure + 1e-9) {
            return sprintf('exposure would be %.2f, over the %.2f limit', $wallet->exposure() + $quoteAmount, $maxExposure);
        }

        if ($wallet->position($symbol) === null && count($wallet->positions()) >= $this->maxPositions) {
            return "already holding {$this->maxPositions} position(s)";
        }

        if ($quoteAmount > $wallet->quote() + 1e-9) {
            return sprintf('only %.2f %s left', $wallet->quote(), $wallet->quoteAsset);
        }

        return null;
    }

    public function stopLossHit(Position $position, float $price): bool
    {
        return $this->stopLossPct > 0 && $price <= $position->averagePrice * (1 - $this->stopLossPct);
    }

    /** @return array<string, float|int|string> */
    public function toArray(): array
    {
        return [
            'maxOrderQuote' => $this->maxOrderQuote->value(),
            'maxExposureQuote' => $this->maxExposureQuote->value(),
            'maxPositions' => $this->maxPositions,
            'stopLossPct' => $this->stopLossPct,
        ];
    }
}
