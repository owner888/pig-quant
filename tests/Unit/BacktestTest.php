<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigQuant\Backtest;
use PigQuant\History;
use Quant\Backtest\Backtester;
use Quant\Engine;
use Quant\Exchange\Binance;
use Quant\Market\Candle;
use Quant\Market\Interval;
use Quant\Risk\RiskRules;
use Quant\Strategy\MovingAverageCross;
use Quant\Wallet\DryRunWallet;

final class BacktestTest extends TestCase
{
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

    public function testACrossUpThenDownIsOneRoundTripWithTheFiguresToMatch(): void
    {
        $engine = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules(100.0, 500.0, 1, 0.0), ['BTCUSDT'], '1m', 100.0);
        // warm-up falls, then the range: a rise (cross up on 12), flat, a fall (cross down on 5)
        $warmup = self::candles([10.0, 9.0, 8.0, 7.0]);
        $range = self::candles([12.0, 12.0, 12.0, 5.0, 5.0], 4);

        $report = Backtester::run($engine, 'BTCUSDT', $warmup, $range);

        $this->assertSame(2, $report->trades);
        $this->assertSame(1, $report->closedTrades);
        $this->assertSame(1, $report->losses, 'bought at 12, sold at 5');
        $this->assertSame(0.0, $report->winRate);
        // 100 USDT at 12 = 8.33 BTC; sold at 5 = 41.67 → equity 941.67
        $this->assertEqualsWithDelta(941.67, $report->finalEquity, 0.01);
        $this->assertEqualsWithDelta(-0.0583, $report->returnPct, 0.001);
        $this->assertEqualsWithDelta(1000.0 - 941.67, $report->maxDrawdown, 0.01, 'the fall from the 1000 peak');
        $this->assertNull($report->openPosition);
        $this->assertCount(5, $report->curve);
        $this->assertSame($range[0]->openTime, $report->from);
        $this->assertSame($range[4]->closeTime, $report->to);
        $this->assertEqualsWithDelta(5.0 / 12.0 - 1, $report->buyAndHoldPct, 0.001, 'held from the first open to the last close');
        $this->assertSame(3 * 60_000, $report->averageHoldMs, 'bought on candle 4, sold on candle 7');
    }

    public function testAnOpenPositionIsValuedNotSold(): void
    {
        $engine = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules(100.0, 500.0, 1, 0.0), ['BTCUSDT'], '1m', 100.0);
        $report = Backtester::run($engine, 'BTCUSDT', self::candles([10.0, 9.0, 8.0, 7.0]), self::candles([12.0, 14.0], 4));

        $this->assertSame(1, $report->trades);
        $this->assertNotNull($report->openPosition);
        $this->assertEqualsWithDelta(1000.0 + 100.0 * (14.0 / 12.0 - 1), $report->finalEquity, 0.01);
        $this->assertSame(0, $report->closedTrades);
    }

    public function testTheCurveIsDownsampledWithTheLastPointKept(): void
    {
        $engine = new Engine(new Binance(), new MovingAverageCross(9, 21), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules(100.0, 500.0, 1, 0.0), ['BTCUSDT'], '1m', 100.0);
        $range = self::candles(array_fill(0, 1200, 10.0));

        $report = Backtester::run($engine, 'BTCUSDT', [], $range);

        $this->assertCount(500, $report->curve);
        $this->assertSame($range[1199]->closeTime, $report->curve[499][0]);
        $this->assertSame($range[0]->closeTime, $report->curve[0][0]);
    }

    public function testMonthsCoverTheRange(): void
    {
        $from = Backtest::parseDate('2026-01-15');
        $to = Backtest::parseDate('2026-03-02');

        $this->assertSame(['2026-01', '2026-02', '2026-03'], array_column(History::months($from, $to), 2));
        $this->assertSame(Backtest::parseDate('2026-01-01'), History::months($from, $to)[0][0]);
        $this->assertSame(Backtest::parseDate('2026-02-01'), History::months($from, $to)[0][1]);
        $this->assertSame(['2025-12', '2026-01'], array_column(History::months(Backtest::parseDate('2025-12-31'), Backtest::parseDate('2026-01-01T00:01')), 2), 'across a year end');
    }

    public function testArgsParseIntoARangeAndOverrides(): void
    {
        $spec = Backtest::parseArgs('btc/usdt 5m 2026-10-01 2026-11-01 fast=5 slow=20');

        $this->assertSame('btc/usdt', $spec['symbol']);
        $this->assertSame('5m', $spec['interval']);
        $this->assertSame(1_790_812_800_000, $spec['from']);
        $this->assertSame(1_793_491_200_000, $spec['to']);
        $this->assertSame(['fast' => '5', 'slow' => '20'], $spec['overrides']);

        $config = Backtest::configure(\PigQuant\Config::DEFAULTS, $spec['overrides'] + ['stopLossPct' => '0.03']);
        $this->assertSame(5, $config['strategy']['fast']);
        $this->assertSame(20, $config['strategy']['slow']);
        $this->assertSame(0.03, $config['risk']['stopLossPct']);
    }

    public function testBadArgsSayWhatIsWanted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Usage');
        Backtest::parseArgs('BTCUSDT 1m');
    }

    public function testAnUnknownOverrideIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Backtest::configure(\PigQuant\Config::DEFAULTS, ['symbols' => '1']);
    }

    public function testIntervalsHaveLengths(): void
    {
        $this->assertSame(60_000, Interval::milliseconds('1m'));
        $this->assertSame(4 * 3_600_000, Interval::milliseconds('4h'));
        $this->assertSame(86_400_000, Interval::milliseconds('1d'));

        $this->expectException(\InvalidArgumentException::class);
        Interval::milliseconds('1x');
    }

    public function testTheCandlesRequestCarriesTheRange(): void
    {
        $request = (new Binance())->candlesRequest('BTCUSDT', '1m', 1000, 1_700_000_000_000, 1_700_003_600_000);

        $this->assertStringContainsString('startTime=1700000000000', $request->url);
        $this->assertStringContainsString('endTime=1700003600000', $request->url);
        $this->assertStringNotContainsString('startTime', (new Binance())->candlesRequest('BTCUSDT', '1m', 10)->url);
    }
}
