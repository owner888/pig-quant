<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quant\Engine;
use Quant\Exchange\Binance;
use Quant\Indicator\Sma;
use Quant\Market\Candle;
use Quant\Market\Series;
use Quant\Market\Side;
use Quant\Risk\RiskRules;
use Quant\Strategy\MovingAverageCross;
use Quant\Strategy\Strategy;
use Quant\Wallet\DryRunWallet;
use Quant\Wallet\Position;

final class EngineTest extends TestCase
{
    public function testSmaIsTheMeanOfTheLastPeriodValues(): void
    {
        $values = [1.0, 2.0, 3.0, 4.0, 5.0];

        $this->assertSame(4.0, Sma::at($values, 3));
        $this->assertSame(3.0, Sma::at($values, 3, 1));
        $this->assertNull(Sma::at($values, 6));
    }

    public function testTheSeriesReplacesAFormingCandleAndKeepsOrder(): void
    {
        $series = new Series('BTCUSDT', '1m', 3);
        $series->add(self::candle(2, 10.0));
        $series->add(self::candle(1, 9.0));
        $series->add(self::candle(3, 11.0, closed: false));
        $series->add(self::candle(3, 12.0, closed: false));

        $this->assertSame([9.0, 10.0, 12.0], array_map(static fn (Candle $c): float => $c->close, $series->all()));
        $this->assertSame([9.0, 10.0], $series->closes());

        $series->add(self::candle(4, 13.0));
        $this->assertSame(3, $series->count());
        $this->assertSame(10.0, $series->all()[0]->close, 'the oldest candle was dropped');
    }

    public function testMaCrossBuysOnTheCrossUpAndSellsOnTheCrossDown(): void
    {
        $strategy = new MovingAverageCross(2, 3);
        $series = new Series('BTCUSDT', '1m');

        // falling, then a sharp rise: fast SMA crosses above slow on the last candle
        foreach ([10.0, 9.0, 8.0, 7.0, 12.0] as $i => $close) {
            $series->add(self::candle($i + 1, $close));
        }

        $this->assertSame(Side::Buy, $strategy->onCandle($series, null));
        $this->assertNull($strategy->onCandle($series, new Position('BTCUSDT', 1.0, 10.0, 0)), 'already long: no second buy');

        // then a fall: fast crosses below slow
        foreach ([12.0, 12.0, 5.0] as $i => $close) {
            $series->add(self::candle($i + 6, $close));
        }

        $this->assertSame(Side::Sell, $strategy->onCandle($series, new Position('BTCUSDT', 1.0, 10.0, 0)));
        $this->assertNull($strategy->onCandle($series, null), 'nothing to sell');
    }

    public function testTheEngineTradesClosedCandlesThroughTheRiskRules(): void
    {
        $engine = new Engine(
            exchange: new Binance(),
            strategy: new AlwaysBuy(),
            wallet: new DryRunWallet('USDT', 1000.0, 0.0, 0.0),
            risk: new RiskRules(100.0, 150.0, 2, 0.1),
            symbols: ['BTCUSDT', 'ETHUSDT'],
            interval: '1m',
            orderQuote: 100.0,
        );
        $events = [];
        $engine->onEvent(static function (string $event, array $data) use (&$events): void {
            $events[] = $event;
        });

        $engine->onCandle('BTCUSDT', self::candle(1, 100.0, closed: false));
        $this->assertSame([], $events, 'a forming candle asks the strategy nothing');

        $engine->onCandle('BTCUSDT', self::candle(1, 100.0));
        $this->assertSame(['signal', 'trade'], $events);
        $this->assertNotNull($engine->wallet->position('BTCUSDT'));

        $engine->onCandle('ETHUSDT', self::candle(1, 10.0));
        $this->assertSame(['signal', 'trade', 'signal', 'refused'], $events, 'the second buy would put exposure at 200 over the 150 limit');

        // the stop-loss closes BTC on a forming candle 10% under the average price
        $engine->onCandle('BTCUSDT', self::candle(2, 89.0, closed: false));
        $this->assertNull($engine->wallet->position('BTCUSDT'));
        $this->assertSame('stop-loss', $engine->wallet->trades()[1]->reason);
        $this->assertEqualsWithDelta(989.0, $engine->wallet->equity([]), 1e-9);
    }

    public function testAManualOrderUsesTheLastPriceAndTheSameRules(): void
    {
        $engine = new Engine(new Binance(), new AlwaysBuy(), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules(100.0, 500.0, 2, 0.0), ['BTCUSDT'], '1m', 100.0);

        try {
            $engine->order(Side::Buy, 'BTCUSDT');
            $this->fail('no price yet');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('No price', $e->getMessage());
        }

        $engine->seed('BTCUSDT', [self::candle(1, 50.0)]);
        $trade = $engine->order(Side::Buy, 'btc/usdt', 40.0, null, 'test');
        $this->assertSame(50.0, $trade->price);
        $this->assertEqualsWithDelta(0.8, $trade->quantity, 1e-12);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('per-order limit');
        $engine->order(Side::Buy, 'BTCUSDT', 150.0);
    }

    private static function candle(int $minute, float $close, bool $closed = true): Candle
    {
        $open = $minute * 60_000;

        return new Candle($open, $close, $close, $close, $close, 1.0, $open + 59_999, $closed);
    }
}

/** Buys on every closed candle: exercises the engine's risk path, not the strategy's. */
final class AlwaysBuy implements Strategy
{
    public function name(): string
    {
        return 'always-buy';
    }

    public function warmup(): int
    {
        return 1;
    }

    public function parameters(): array
    {
        return [];
    }

    public function onCandle(Series $series, ?Position $position): ?Side
    {
        return Side::Buy;
    }
}
