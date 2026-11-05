<?php

declare(strict_types=1);

namespace Quant;

use Closure;
use Quant\Exchange\Exchange;
use Quant\Market\Candle;
use Quant\Market\Series;
use Quant\Market\Side;
use Quant\Risk\Limit;
use Quant\Risk\RiskRules;
use Quant\Strategy\Strategy;
use Quant\Wallet\DryRunWallet;
use Quant\Wallet\Trade;

/**
 * The dry-run engine: candles in, paper trades out.
 *
 * It owns nothing that does I/O. The host feeds it candles (`seed()` from a REST answer,
 * `onCandle()` from a stream), reads `status()`, and hears what happened through `onEvent()`:
 *
 * - `trade` — a fill, `['trade' => Trade]`
 * - `refused` — the strategy or a person asked for a buy the risk rules rejected, `['symbol', 'reason']`
 * - `signal` — the strategy answered on a closed candle, `['symbol', 'side']`
 *
 * One strategy, one interval, any number of symbols; each symbol has its own series and position.
 */
final class Engine
{
    /** @var array<string, Series> by symbol */
    private array $series = [];

    /** @var array<string, float> last price seen by symbol */
    private array $prices = [];

    /** @var list<Closure(string, array<string, mixed>): void> */
    private array $listeners = [];

    private int $candlesSeen = 0;

    /** What one strategy buy spends: an amount, or a share of the free cash (`"all"` compounds). */
    public readonly Limit $orderQuote;

    /**
     * @param list<string> $symbols
     * @param Limit|float|string $orderQuote what one strategy buy spends: `100`, `"50%"` of the free cash, or `"all"` of it
     */
    public function __construct(
        public readonly Exchange $exchange,
        public readonly Strategy $strategy,
        public readonly DryRunWallet $wallet,
        public readonly RiskRules $risk,
        public readonly array $symbols,
        public readonly string $interval,
        Limit|float|string $orderQuote,
    ) {
        if ($symbols === []) {
            throw new \InvalidArgumentException('The engine trades at least one symbol.');
        }

        $this->orderQuote = Limit::parse($orderQuote);

        foreach ($symbols as $symbol) {
            $this->series[$exchange->symbol($symbol)] = new Series($exchange->symbol($symbol), $interval);
        }
    }

    /** @param Closure(string, array<string, mixed>): void $listener */
    public function onEvent(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** History, before the stream: no signals are taken from it. @param list<Candle> $candles */
    public function seed(string $symbol, array $candles): void
    {
        $series = $this->seriesOf($symbol);
        $series->addAll($candles);
        $last = $series->last();

        if ($last !== null) {
            $this->prices[$series->symbol] = $last->close;
        }
    }

    /** A candle from the stream: forming or closed. */
    public function onCandle(string $symbol, Candle $candle): void
    {
        $series = $this->seriesOf($symbol);
        $symbol = $series->symbol;
        $series->add($candle);
        $this->prices[$symbol] = $candle->close;
        $this->candlesSeen++;

        $position = $this->wallet->position($symbol);

        if ($position !== null && $this->risk->stopLossHit($position, $candle->close)) {
            $trade = $this->wallet->sell($symbol, null, $candle->close, $candle->closeTime, 'stop-loss');
            $this->emit('trade', ['trade' => $trade]);

            return;
        }

        if (!$candle->closed || count($series->closed()) < $this->strategy->warmup()) {
            return;
        }

        $side = $this->strategy->onCandle($series, $position);

        if ($side === null) {
            return;
        }

        $this->emit('signal', ['symbol' => $symbol, 'side' => $side->value]);

        if ($side === Side::Buy) {
            if ($position !== null) {
                return;
            }

            $quoteAmount = $this->orderQuote->quote($this->wallet->quote());
            $refused = $this->risk->refuseBuy($symbol, $quoteAmount, $this->wallet, $this->equity());

            if ($refused !== null) {
                $this->emit('refused', ['symbol' => $symbol, 'reason' => $refused]);

                return;
            }

            $trade = $this->wallet->buy($symbol, $quoteAmount, $candle->close, $candle->closeTime, $this->strategy->name());
        } else {
            if ($position === null) {
                return;
            }

            $trade = $this->wallet->sell($symbol, null, $candle->close, $candle->closeTime, $this->strategy->name());
        }

        $this->emit('trade', ['trade' => $trade]);
    }

    /**
     * An order a person (or the model) asks for, at the last price seen, through the same risk
     * rules. A buy spends `$quoteAmount` (the configured order size when null); a sell closes
     * `$quantity` of the position (all of it when null).
     *
     * @throws \RuntimeException when the risk rules refuse it, or there is no price yet
     */
    public function order(Side $side, string $symbol, ?float $quoteAmount = null, ?float $quantity = null, string $reason = 'manual'): Trade
    {
        $symbol = $this->seriesOf($symbol)->symbol;
        $price = $this->prices[$symbol] ?? throw new \RuntimeException("No price for {$symbol} yet: the engine has seen no candle.");
        $time = (int) floor(microtime(true) * 1000);

        if ($side === Side::Buy) {
            $quoteAmount ??= $this->orderQuote->quote($this->wallet->quote());
            $refused = $this->risk->refuseBuy($symbol, $quoteAmount, $this->wallet, $this->equity());

            if ($refused !== null) {
                $this->emit('refused', ['symbol' => $symbol, 'reason' => $refused]);

                throw new \RuntimeException("Refused: {$refused}.");
            }

            $trade = $this->wallet->buy($symbol, $quoteAmount, $price, $time, $reason);
        } else {
            $trade = $this->wallet->sell($symbol, $quantity, $price, $time, $reason);
        }

        $this->emit('trade', ['trade' => $trade]);

        return $trade;
    }

    /** Cash plus every position at the last price seen for it. */
    public function equity(): float
    {
        return $this->wallet->equity(array_intersect_key($this->prices, $this->wallet->positions()));
    }

    public function price(string $symbol): ?float
    {
        return $this->prices[$this->exchange->symbol($symbol)] ?? null;
    }

    public function series(string $symbol): Series
    {
        return $this->seriesOf($symbol);
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        $positions = [];

        foreach ($this->wallet->positions() as $symbol => $position) {
            $price = $this->prices[$symbol] ?? $position->averagePrice;
            $positions[] = $position->toArray() + [
                'price' => $price,
                'unrealizedPnl' => $position->unrealizedPnl($price),
            ];
        }

        return [
            'exchange' => $this->exchange->name(),
            'strategy' => $this->strategy->name(),
            'parameters' => $this->strategy->parameters(),
            'interval' => $this->interval,
            'symbols' => array_keys($this->series),
            'orderQuote' => $this->orderQuote->value(),
            'risk' => $this->risk->toArray(),
            'quoteAsset' => $this->wallet->quoteAsset,
            'initialQuote' => $this->wallet->initialQuote,
            'quote' => $this->wallet->quote(),
            'equity' => $this->equity(),
            'realizedPnl' => $this->wallet->realizedPnl(),
            'positions' => $positions,
            'trades' => count($this->wallet->trades()),
            'prices' => $this->prices,
            'candles' => array_map(static fn (Series $s): int => $s->count(), $this->series),
            'candlesSeen' => $this->candlesSeen,
        ];
    }

    private function seriesOf(string $symbol): Series
    {
        $symbol = $this->exchange->symbol($symbol);

        return $this->series[$symbol] ?? throw new \InvalidArgumentException("The engine is not trading {$symbol}; it trades " . implode(', ', array_keys($this->series)) . '.');
    }

    /** @param array<string, mixed> $data */
    private function emit(string $event, array $data): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event, $data);
        }
    }
}
