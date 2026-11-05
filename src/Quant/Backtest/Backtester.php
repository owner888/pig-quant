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
     * One symbol over its history.
     *
     * @param list<Candle> $warmup candles before the range, for the strategy's indicators; no trades are taken from them
     * @param list<Candle> $candles the range, oldest first
     * @param Closure(int, int): void|null $progress told how many candles are done, of how many, now and then
     */
    public static function run(Engine $engine, string $symbol, array $warmup, array $candles, ?Closure $progress = null): Report
    {
        $symbol = $engine->exchange->symbol($symbol);

        return self::runBasket($engine, [$symbol => $warmup], [$symbol => $candles], $progress);
    }

    /**
     * Several symbols over the same range, through one engine: the candles are merged into one
     * timeline by open time, and at each time every symbol's candle goes through `onCandle()` —
     * so the wallet, the position count and the exposure limit are shared, as in the dry run.
     * Buy & hold is the equal-weight basket: a share of the initial balance in each symbol.
     *
     * @param array<string, list<Candle>> $warmup by symbol
     * @param array<string, list<Candle>> $candles by symbol, oldest first
     * @param Closure(int, int): void|null $progress told how many timeline steps are done, of how many, now and then
     */
    public static function runBasket(Engine $engine, array $warmup, array $candles, ?Closure $progress = null): Report
    {
        $symbols = [];

        foreach (array_keys($candles) as $symbol) {
            $symbols[] = $engine->exchange->symbol((string) $symbol);
        }

        if ($symbols === [] || array_filter($candles) === []) {
            throw new \InvalidArgumentException('A backtest needs at least one candle.');
        }

        $candles = array_combine($symbols, array_values($candles));
        $warmup = array_combine($symbols, array_values($warmup + array_fill_keys(array_keys($candles), [])));

        foreach ($symbols as $symbol) {
            $engine->seed($symbol, $warmup[$symbol] ?? []);
        }

        // One timeline: every open time any symbol has a candle at, in order.
        $byTime = [];

        foreach ($candles as $symbol => $list) {
            foreach ($list as $candle) {
                $byTime[$candle->openTime][$symbol] = $candle;
            }
        }

        ksort($byTime);

        $curve = [];
        $total = count($byTime);
        $step = max(1, intdiv($total, 200));
        $index = 0;
        $lastClose = null;

        foreach ($byTime as $atTime) {
            foreach ($symbols as $symbol) {
                $candle = $atTime[$symbol] ?? null;

                if ($candle === null) {
                    continue;
                }

                $engine->onCandle($symbol, new Candle($candle->openTime, $candle->open, $candle->high, $candle->low, $candle->close, $candle->volume, $candle->closeTime, true));
                $lastClose = max($lastClose ?? 0, $candle->closeTime);
            }

            $curve[] = [$lastClose, $engine->equity()];

            if ($progress !== null && ($index % $step === 0 || $index === $total - 1)) {
                $progress($index + 1, $total);
            }

            $index++;
        }

        return self::report($engine, $symbols, $candles, $byTime, $curve);
    }

    /**
     * @param list<string> $symbols
     * @param array<string, list<Candle>> $candles by symbol
     * @param array<int, array<string, Candle>> $byTime the timeline, sorted
     * @param list<array{0: int, 1: float}> $curve
     */
    private static function report(Engine $engine, array $symbols, array $candles, array $byTime, array $curve): Report
    {
        $wallet = $engine->wallet;
        $initial = $wallet->initialQuote;
        $final = $engine->equity();
        $from = array_key_first($byTime);
        $to = max(array_map(static fn (array $list): int => $list[count($list) - 1]->closeTime, array_filter($candles)));
        $count = array_sum(array_map('count', $candles));

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

        // Buy & hold: the equal-weight basket, a share of the initial balance in each symbol from its
        // first open to its last close, costs as the wallet charges them — and the basket's own
        // drawdown along the timeline, what the strategy's drawdown is measured against.
        $share = $initial / count($symbols);
        $units = [];
        $holdPrices = [];

        foreach ($symbols as $symbol) {
            $open = $candles[$symbol][0]->open ?? 0.0;
            $units[$symbol] = $open > 0 ? $share * (1 - $wallet->feeRate) / ($open * (1 + $wallet->slippage)) : 0.0;
            $holdPrices[$symbol] = $open;
        }

        $holdPeak = 0.0;
        $holdDrawdownPct = 0.0;
        $holdValue = 0.0;

        foreach ($byTime as $atTime) {
            foreach ($atTime as $symbol => $candle) {
                $holdPrices[$symbol] = $candle->close;
            }

            $holdValue = 0.0;

            foreach ($symbols as $symbol) {
                $holdValue += $units[$symbol] > 0 ? $units[$symbol] * $holdPrices[$symbol] : $share;
            }

            $holdPeak = max($holdPeak, $holdValue);

            if ($holdPeak > 0) {
                $holdDrawdownPct = max($holdDrawdownPct, ($holdPeak - $holdValue) / $holdPeak);
            }
        }

        $buyAndHold = $holdValue;

        // Per symbol: what each contributed.
        $bySymbol = [];

        foreach ($symbols as $symbol) {
            $own = array_values(array_filter($trades, static fn (Trade $t): bool => $t->symbol === $symbol));
            $ownSells = array_filter($own, static fn (Trade $t): bool => $t->side === Side::Sell);
            $bySymbol[$symbol] = [
                'trades' => count($own),
                'closedTrades' => count($ownSells),
                'wins' => count(array_filter($ownSells, static fn (Trade $t): bool => ($t->pnl ?? 0.0) > 0)),
                'pnl' => array_sum(array_map(static fn (Trade $t): float => $t->pnl ?? 0.0, $ownSells)),
                'fees' => array_sum(array_map(static fn (Trade $t): float => $t->fee, $own)),
                'buyAndHoldPct' => $units[$symbol] > 0 ? $units[$symbol] * $holdPrices[$symbol] / $share - 1 : 0.0,
                'openPosition' => $wallet->position($symbol)?->toArray(),
            ];
        }

        return new Report(
            symbol: implode('+', $symbols),
            symbols: $symbols,
            interval: $engine->interval,
            strategy: $engine->strategy->name(),
            parameters: $engine->strategy->parameters(),
            risk: $engine->risk->toArray(),
            orderQuote: $engine->orderQuote->value(),
            feeRate: $wallet->feeRate,
            slippage: $wallet->slippage,
            from: $from,
            to: $to,
            candles: $count,
            initialQuote: $initial,
            finalEquity: $final,
            returnPct: ($final - $initial) / $initial,
            buyAndHoldPct: ($buyAndHold - $initial) / $initial,
            buyAndHoldDrawdownPct: $holdDrawdownPct,
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
            openPosition: count($symbols) === 1 ? $wallet->position($symbols[0])?->toArray() : null,
            bySymbol: $bySymbol,
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
