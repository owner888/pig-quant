<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quant\Backtest\Backtester;
use Quant\Backtest\Sweep;
use Quant\Engine;
use Quant\Exchange\Binance;
use Quant\Market\Candle;
use Quant\Risk\Limit;
use Quant\Risk\RiskRules;
use Quant\Strategy\MovingAverageCross;
use Quant\Wallet\DryRunWallet;

final class LimitTest extends TestCase
{
    public function testAnAmountOrAShareParsesAndReadsBack(): void
    {
        $this->assertSame(100.0, Limit::parse(100)->quote(5000.0));
        $this->assertSame(100.0, Limit::parse('100')->quote(5000.0));
        $this->assertSame(1250.0, Limit::parse('25%')->quote(5000.0));
        $this->assertSame(5000.0, Limit::parse('all')->quote(5000.0));
        $this->assertSame(5000.0, Limit::parse(' ALL ')->quote(5000.0));

        $this->assertSame('100', Limit::parse(100)->label());
        $this->assertSame('25%', Limit::parse('25%')->label());
        $this->assertSame('all', Limit::parse('100%')->label());
        $this->assertSame(100.0, Limit::parse(100)->value());
        $this->assertSame('all', Limit::parse('all')->value());

        $this->assertTrue(Limit::isShare('50%'));
        $this->assertTrue(Limit::isShare('all'));
        $this->assertFalse(Limit::isShare('50'));
        $this->assertFalse(Limit::isShare(50));
    }

    public function testNonsenseIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('write 100, 25% or all');
        Limit::parse('lots');
    }

    public function testAShareOverOneHundredPercentIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Limit::parse('150%');
    }

    public function testAllCompoundsTheWalletAcrossRoundTrips(): void
    {
        // Two round trips, no costs. A fixed 100 stake returns r1 and r2 on 100 each; "all" returns
        // r1 on the whole wallet and then r2 on what that left — the same two trades, compounded.
        $warmup = self::candles([10.0, 9.0, 8.0, 7.0]);
        $range = self::candles([12.0, 12.0, 12.0, 14.4, 14.4, 14.4, 10.0, 10.0, 10.0, 12.0, 12.0, 12.0, 14.4, 14.4, 14.4, 10.0], 4);

        $fixed = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules('all', 'all', 1, 0.0), ['BTCUSDT'], '1m', 100.0);
        $all = new Engine(new Binance(), new MovingAverageCross(2, 3), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules('all', 'all', 1, 0.0), ['BTCUSDT'], '1m', 'all');

        $fixedReport = Backtester::run($fixed, 'BTCUSDT', $warmup, $range);
        $allReport = Backtester::run($all, 'BTCUSDT', $warmup, $range);

        $this->assertSame($fixedReport->closedTrades, $allReport->closedTrades, 'the same signals');
        $this->assertSame(2, $allReport->closedTrades);
        $sells = array_values(array_filter($fixedReport->tradeList, static fn (array $t): bool => $t['side'] === 'sell'));
        [$r1, $r2] = [$sells[0]['pnl'] / 100.0, $sells[1]['pnl'] / 100.0];
        $this->assertEqualsWithDelta(1000.0 + 100.0 * ($r1 + $r2), $fixedReport->finalEquity, 0.01);
        $this->assertEqualsWithDelta(1000.0 * (1 + $r1) * (1 + $r2), $allReport->finalEquity, 0.01);
        $this->assertSame('all', $allReport->orderQuote);
        $this->assertSame(100.0, $fixedReport->orderQuote);
        $this->assertSame(['maxOrderQuote' => 'all', 'maxExposureQuote' => 'all', 'maxPositions' => 1, 'stopLossPct' => 0.0], $allReport->risk);
        // every buy spent the whole wallet: the second one spent what the first round trip left
        $buys = array_values(array_filter($allReport->tradeList, static fn (array $t): bool => $t['side'] === 'buy'));
        $this->assertEqualsWithDelta(1000.0, $buys[0]['quoteAmount'], 0.01);
        $this->assertEqualsWithDelta(1000.0 * (1 + $r1), $buys[1]['quoteAmount'], 0.01);
    }

    public function testAShareLimitIsMeasuredAgainstTheEquity(): void
    {
        $wallet = new DryRunWallet('USDT', 1000.0, 0.0, 0.0);
        $rules = new RiskRules('10%', '50%', 3, 0.0);

        $this->assertNull($rules->refuseBuy('BTCUSDT', 100.0, $wallet, 1000.0));
        $this->assertStringContainsString('per-order limit', (string) $rules->refuseBuy('BTCUSDT', 101.0, $wallet, 1000.0));
        $this->assertNull($rules->refuseBuy('BTCUSDT', 200.0, $wallet, 2000.0), 'the limit grows with the equity');
    }

    public function testASweepKeepsAShareAsOneFixedValue(): void
    {
        $split = Sweep::split(['fast' => '10..20:10', 'orderQuote' => 'all', 'maxExposureQuote' => '100%']);

        $this->assertSame(['fast' => [10, 20]], $split['sweeps']);
        $this->assertSame(['orderQuote' => 'all', 'maxExposureQuote' => 'all'], $split['fixed']);
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
