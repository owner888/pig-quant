<?php

declare(strict_types=1);

namespace Quant\Backtest;

/** What a backtest found. Percentages are fractions (0.05 = 5%); times are Unix milliseconds. */
final readonly class Report
{
    /**
     * @param array<string, mixed> $parameters
     * @param array<string, float|int> $risk
     * @param array<string, mixed>|null $openPosition
     * @param list<array{0: int, 1: float}> $curve equity after each candle, downsampled: `[closeTime, equity]`
     * @param list<array<string, mixed>> $tradeList
     */
    public function __construct(
        public string $symbol,
        public string $interval,
        public string $strategy,
        public array $parameters,
        public array $risk,
        public float $orderQuote,
        public float $feeRate,
        public float $slippage,
        public int $from,
        public int $to,
        public int $candles,
        public float $initialQuote,
        public float $finalEquity,
        public float $returnPct,
        public float $buyAndHoldPct,
        public float $maxDrawdown,
        public float $maxDrawdownPct,
        public int $trades,
        public int $closedTrades,
        public int $wins,
        public int $losses,
        public float $winRate,
        public float $profitFactor,
        public float $grossProfit,
        public float $grossLoss,
        public float $fees,
        public float $feesPct,
        public int $averageHoldMs,
        public ?array $openPosition,
        public array $curve,
        public array $tradeList,
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $out = get_object_vars($this);
        $out['profitFactor'] = is_finite($this->profitFactor) ? $this->profitFactor : null;

        return $out;
    }

    /** The figures a person reads, one per line. @return list<string> */
    public function lines(): array
    {
        $pct = static fn (float $v): string => sprintf('%s%.2f%%', $v >= 0 ? '+' : '', $v * 100);
        $hold = $this->averageHoldMs;
        $holdText = $hold >= 86_400_000 ? sprintf('%.1f d', $hold / 86_400_000) : ($hold >= 3_600_000 ? sprintf('%.1f h', $hold / 3_600_000) : sprintf('%.0f min', $hold / 60_000));

        return [
            sprintf('%s %s · %s %s · %s → %s · %d candles', $this->symbol, $this->interval, $this->strategy, json_encode($this->parameters), gmdate('Y-m-d', intdiv($this->from, 1000)), gmdate('Y-m-d', intdiv($this->to, 1000)), $this->candles),
            sprintf('Equity %.2f → %.2f (%s) · buy & hold %s', $this->initialQuote, $this->finalEquity, $pct($this->returnPct), $pct($this->buyAndHoldPct)),
            sprintf('Max drawdown %.2f (%.2f%%)', $this->maxDrawdown, $this->maxDrawdownPct * 100),
            sprintf('Trades %d (%d closed): %d won, %d lost, win rate %.1f%%, profit factor %s', $this->trades, $this->closedTrades, $this->wins, $this->losses, $this->winRate * 100, is_finite($this->profitFactor) ? sprintf('%.2f', $this->profitFactor) : '∞'),
            sprintf('Fees %.2f (%.2f%% of initial) · average hold %s', $this->fees, $this->feesPct * 100, $holdText),
        ] + ($this->openPosition !== null ? [5 => sprintf('Open at the end: %s %s @ %.2f (valued at the last close)', $this->openPosition['symbol'], rtrim(rtrim(sprintf('%.8f', $this->openPosition['quantity']), '0'), '.'), $this->openPosition['averagePrice'])] : []);
    }
}
