<?php

declare(strict_types=1);

namespace Quant\Backtest;

use Closure;
use Quant\Engine;
use Quant\Market\Candle;
use Quant\Market\Side;
use Quant\Wallet\Trade;

/**
 * Runs an engine over history as if it were the stream: the warm-up candles are seeded, every
 * candle of the range goes through `onCandle()` as a closed candle, and the equity is written
 * down after each. The engine is the one the dry run uses — same wallet, risk rules and
 * strategy — so what the report says is what that configuration would have done.
 *
 * A position still open at the end is valued at the last close, not sold: a forced sale would
 * charge a trade the strategy never made.
 */
final class Backtester
{
    /**
     * @param list<Candle> $warmup candles before the range, for the strategy's indicators; no trades are taken from them
     * @param list<Candle> $candles the range, oldest first
     * @param Closure(int, int): void|null $progress told how many candles are done, of how many, now and then
     */
    public static function run(Engine $engine, string $symbol, array $warmup, array $candles, ?Closure $progress = null): Report
    {
        if ($candles === []) {
            throw new \InvalidArgumentException('A backtest needs at least one candle.');
        }

        $symbol = $engine->exchange->symbol($symbol);
        $engine->seed($symbol, $warmup);

        $curve = [];
        $total = count($candles);
        $step = max(1, intdiv($total, 200));

        foreach ($candles as $index => $candle) {
            $engine->onCandle($symbol, new Candle($candle->openTime, $candle->open, $candle->high, $candle->low, $candle->close, $candle->volume, $candle->closeTime, true));
            $curve[] = [$candle->closeTime, $engine->equity()];

            if ($progress !== null && ($index % $step === 0 || $index === $total - 1)) {
                $progress($index + 1, $total);
            }
        }

        return self::report($engine, $symbol, $candles, $curve);
    }

    /**
     * @param list<Candle> $candles
     * @param list<array{0: int, 1: float}> $curve
     */
    private static function report(Engine $engine, string $symbol, array $candles, array $curve): Report
    {
        $wallet = $engine->wallet;
        $initial = $wallet->initialQuote;
        $final = $engine->equity();
        $first = $candles[0];
        $last = $candles[count($candles) - 1];

        // Max drawdown: the deepest fall from a running peak, in quote and as a share of that peak.
        $peak = $initial;
        $drawdown = 0.0;
        $drawdownPct = 0.0;

        foreach ($curve as [, $equity]) {
            $peak = max($peak, $equity);
            $fall = $peak - $equity;

            if ($fall > $drawdown) {
                $drawdown = $fall;
                $drawdownPct = $peak > 0 ? $fall / $peak : 0.0;
            }
        }

        $trades = $wallet->trades();
        $sells = array_values(array_filter($trades, static fn (Trade $t): bool => $t->side === Side::Sell));
        $wins = array_filter($sells, static fn (Trade $t): bool => ($t->pnl ?? 0.0) > 0);
        $losses = array_filter($sells, static fn (Trade $t): bool => ($t->pnl ?? 0.0) <= 0);
        $grossProfit = array_sum(array_map(static fn (Trade $t): float => $t->pnl ?? 0.0, $wins));
        $grossLoss = -array_sum(array_map(static fn (Trade $t): float => $t->pnl ?? 0.0, $losses));
        $fees = array_sum(array_map(static fn (Trade $t): float => $t->fee, $trades));

        // Holding time: the engine sells a position whole, so each sell closes what the buys
        // since the last sell opened; measured from the first of those buys.
        $holds = [];
        $openedAt = null;

        foreach ($trades as $trade) {
            if ($trade->side === Side::Buy) {
                $openedAt ??= $trade->time;
            } elseif ($openedAt !== null) {
                $holds[] = $trade->time - $openedAt;
                $openedAt = null;
            }
        }

        $buyAndHold = $first->open > 0 ? $initial * (1 - $wallet->feeRate) * $last->close / ($first->open * (1 + $wallet->slippage)) : $initial;

        return new Report(
            symbol: $symbol,
            interval: $engine->interval,
            strategy: $engine->strategy->name(),
            parameters: $engine->strategy->parameters(),
            risk: $engine->risk->toArray(),
            orderQuote: $engine->orderQuote,
            feeRate: $wallet->feeRate,
            slippage: $wallet->slippage,
            from: $first->openTime,
            to: $last->closeTime,
            candles: count($candles),
            initialQuote: $initial,
            finalEquity: $final,
            returnPct: ($final - $initial) / $initial,
            buyAndHoldPct: ($buyAndHold - $initial) / $initial,
            maxDrawdown: $drawdown,
            maxDrawdownPct: $drawdownPct,
            trades: count($trades),
            closedTrades: count($sells),
            wins: count($wins),
            losses: count($losses),
            winRate: $sells === [] ? 0.0 : count($wins) / count($sells),
            profitFactor: $grossLoss > 0 ? $grossProfit / $grossLoss : ($grossProfit > 0 ? INF : 0.0),
            grossProfit: $grossProfit,
            grossLoss: $grossLoss,
            fees: $fees,
            feesPct: $fees / $initial,
            averageHoldMs: $holds === [] ? 0 : (int) (array_sum($holds) / count($holds)),
            openPosition: $wallet->position($symbol)?->toArray(),
            curve: self::downsample($curve, 500),
            tradeList: array_map(static fn (Trade $t): array => $t->toArray(), $trades),
        );
    }

    /**
     * At most `$points` of the curve, evenly spaced, the last one always kept.
     *
     * @param list<array{0: int, 1: float}> $curve
     * @return list<array{0: int, 1: float}>
     */
    private static function downsample(array $curve, int $points): array
    {
        $count = count($curve);

        if ($count <= $points) {
            return $curve;
        }

        $out = [];
        $step = ($count - 1) / ($points - 1);

        for ($i = 0; $i < $points; $i++) {
            $out[] = $curve[(int) round($i * $step)];
        }

        return $out;
    }
}
