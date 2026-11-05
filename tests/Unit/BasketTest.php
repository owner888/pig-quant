<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigQuant\Backtest;
use Quant\Backtest\Backtester;
use Quant\Engine;
use Quant\Exchange\Binance;
use Quant\Market\Candle;
use Quant\Risk\RiskRules;
use Quant\Strategy\MovingAverageCross;
use Quant\Wallet\DryRunWallet;

final class BasketTest extends TestCase
{
    public function testSymbolsSplitOnCommas(): void
    {
        $this->assertSame(['BTC-USDT'], Backtest::symbols('BTC-USDT'));
        $this->assertSame(['BTC-USDT', 'ETH-USDT'], Backtest::symbols('BTC-USDT, ETH-USDT,'));
    }

    public function testTwoSymbolsShareOneWalletAndTheReportSaysWhatEachDid(): void
    {
        $engine = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules('all', 'all', 2, 0.0), ['BTCUSDT', 'ETHUSDT'], '1m', 'all');
        // Both cross up on the first candle of the range; BTC then falls (a loss), ETH keeps rising (open at the end).
        $warmup = ['BTCUSDT' => self::candles([10.0, 9.0, 8.0, 7.0]), 'ETHUSDT' => self::candles([100.0, 90.0, 80.0, 70.0])];
        $range = ['BTCUSDT' => self::candles([12.0, 12.0, 12.0, 5.0, 5.0], 4), 'ETHUSDT' => self::candles([120.0, 130.0, 140.0, 150.0, 160.0], 4)];

        $report = Backtester::runBasket($engine, $warmup, $range);

        $this->assertSame('BTCUSDT+ETHUSDT', $report->symbol);
        $this->assertSame(['BTCUSDT', 'ETHUSDT'], $report->symbols);
        $this->assertSame(10, $report->candles);
        $this->assertCount(5, $report->curve, 'one point per timeline step, not per candle');
        // "all" over 2 slots: the first buy spent 500, the second the remaining 500
        $buys = array_values(array_filter($report->tradeList, static fn (array $t): bool => $t['side'] === 'buy'));
        $this->assertCount(2, $buys);
        $this->assertEqualsWithDelta(500.0, $buys[0]['quoteAmount'], 0.01);
        $this->assertEqualsWithDelta(500.0, $buys[1]['quoteAmount'], 0.01);
        // BTC: 500 at 12 → sold at 5 = 208.33; ETH: 500 at 120, worth 666.67 at 160 → equity 875
        $this->assertEqualsWithDelta(208.33 + 666.67, $report->finalEquity, 0.01);
        $this->assertSame(1, $report->closedTrades);
        $this->assertSame(1, $report->bySymbol['BTCUSDT']['closedTrades']);
        $this->assertSame(0, $report->bySymbol['ETHUSDT']['closedTrades']);
        $this->assertNotNull($report->bySymbol['ETHUSDT']['openPosition']);
        $this->assertNull($report->openPosition, 'a basket has no single open position');
        // equal-weight hold: 500 in each from the first open — BTC 12 → 5, ETH 120 → 160
        $this->assertEqualsWithDelta((500 * 5 / 12 + 500 * 160 / 120) / 1000 - 1, $report->buyAndHoldPct, 0.001);
        $this->assertEqualsWithDelta(5.0 / 12.0 - 1, $report->bySymbol['BTCUSDT']['buyAndHoldPct'], 0.001);
        $this->assertStringContainsString('BTCUSDT: 1 closed, 0 won', $report->lines()[5]);
        $this->assertStringContainsString('ETHUSDT: 0 closed', $report->lines()[6]);
        $this->assertStringContainsString('still open', $report->lines()[6]);
    }

    public function testSymbolsWithDifferentTimelinesAreMergedInOrder(): void
    {
        $engine = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules('all', 'all', 2, 0.0), ['BTCUSDT', 'ETHUSDT'], '1m', 'all');
        // ETH's candles start two minutes later than BTC's and there are fewer of them
        $range = ['BTCUSDT' => self::candles([10.0, 10.0, 10.0, 10.0, 10.0], 0), 'ETHUSDT' => self::candles([20.0, 20.0, 20.0], 2)];

        $report = Backtester::runBasket($engine, [], $range);

        $this->assertSame(5, count($report->curve), 'five distinct open times');
        $this->assertSame($range['BTCUSDT'][0]->openTime, $report->from);
        $this->assertSame($range['BTCUSDT'][4]->closeTime, $report->to);
        $this->assertSame(8, $report->candles);
        $this->assertSame(1000.0, $report->finalEquity, 'flat prices, no crosses, nothing traded');
        $this->assertSame(0.0, $report->buyAndHoldDrawdownPct);
    }

    public function testAShareOrderSpreadsOverTheOpenSlots(): void
    {
        $engine = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 900.0, 0.0, 0.0), new RiskRules('all', 'all', 3, 0.0), ['BTCUSDT'], '1m', 'all');

        $this->assertEqualsWithDelta(300.0, $engine->orderSize(), 0.01, 'three slots open: a third of the cash');

        $fixed = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 900.0, 0.0, 0.0), new RiskRules('all', 'all', 3, 0.0), ['BTCUSDT'], '1m', 100.0);
        $this->assertSame(100.0, $fixed->orderSize(), 'a fixed size is not divided');
    }

    /** @param list<float> $closes @return list<Candle> */
    private static function candles(array $closes, int $firstMinute = 0): array
    {
        $out = [];

        foreach ($closes as $i => $close) {
            $open = ($firstMinute + $i) * 60_000;
            $out[] = new Candle($open, $close, $close, $close, $close, 1.0, $open + 59_999, true);
        }

        return $out;
    }
}
