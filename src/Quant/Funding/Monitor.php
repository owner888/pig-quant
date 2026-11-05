<?php

declare(strict_types=1);

namespace Quant\Funding;

/**
 * Watches funding rates against a threshold and says when a symbol's rate goes over it and
 * when it comes back — once each, not every poll — so a carry is only ever considered in the
 * months that pay for it (a measured history shows the rest pay less than a treasury).
 *
 * A rate is per settlement; annualized it is `rate × settlements a day × 365`.
 */
final class Monitor
{
    /** @var array<string, int> symbol → since when it has been over the threshold */
    private array $above = [];

    /**
     * @param float $alertAnnualized the annualized rate that is worth a look (0.15 = 15% a year)
     * @param int $settlementsPerDay 3 on Binance and OKX (every eight hours)
     */
    public function __construct(
        public readonly float $alertAnnualized,
        public readonly int $settlementsPerDay = 3,
    ) {
        if ($alertAnnualized <= 0) {
            throw new \InvalidArgumentException('The alert threshold is a positive annualized rate, such as 0.15.');
        }
    }

    public function annualized(float $rate): float
    {
        return $rate * $this->settlementsPerDay * 365;
    }

    /**
     * A fresh rate for a symbol. Returns the event it causes, or null when nothing changed:
     * `above` the first time the rate clears the threshold, `back` the first time it no longer does.
     *
     * @return array{event: 'above'|'back', symbol: string, rate: float, annualized: float, time: int, since: int}|null
     */
    public function update(string $symbol, float $rate, int $time): ?array
    {
        $annualized = $this->annualized($rate);
        $over = $annualized >= $this->alertAnnualized;
        $since = $this->above[$symbol] ?? null;

        if ($over && $since === null) {
            $this->above[$symbol] = $time;

            return ['event' => 'above', 'symbol' => $symbol, 'rate' => $rate, 'annualized' => $annualized, 'time' => $time, 'since' => $time];
        }

        if (!$over && $since !== null) {
            unset($this->above[$symbol]);

            return ['event' => 'back', 'symbol' => $symbol, 'rate' => $rate, 'annualized' => $annualized, 'time' => $time, 'since' => $since];
        }

        return null;
    }

    /** Since when each symbol has been over the threshold. @return array<string, int> */
    public function above(): array
    {
        return $this->above;
    }
}
