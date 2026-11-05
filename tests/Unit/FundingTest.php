<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use PigQuant\Funding;
use Quant\Exchange\ExchangeError;
use Quant\Funding\BinanceFunding;
use Quant\Funding\Carry;
use Quant\Funding\Monitor;
use Quant\Funding\Rate;

final class FundingTest extends TestCase
{
    public function testTheRequestNamesThePerpetualAndTheRange(): void
    {
        $request = (new BinanceFunding())->request('https://fapi.binance.com/', 'BTC-USDT', 1_700_000_000_000, 1_700_003_600_000);

        $this->assertStringStartsWith('https://fapi.binance.com/fapi/v1/fundingRate?', $request->url);
        $this->assertStringContainsString('symbol=BTCUSDT', $request->url);
        $this->assertStringContainsString('startTime=1700000000000', $request->url);
        $this->assertStringContainsString('endTime=1700003600000', $request->url);
        $this->assertStringContainsString('limit=1000', $request->url);
    }

    public function testAPageParsesOldestFirst(): void
    {
        $body = '[{"symbol":"BTCUSDT","fundingTime":1700028800000,"fundingRate":"0.00020000","markPrice":"1"},{"symbol":"BTCUSDT","fundingTime":1700000000000,"fundingRate":"-0.00010000","markPrice":"1"}]';

        $rates = (new BinanceFunding())->parse($body);

        $this->assertCount(2, $rates);
        $this->assertSame(1_700_000_000_000, $rates[0]->time);
        $this->assertSame(-0.0001, $rates[0]->rate);
        $this->assertSame(0.0002, $rates[1]->rate);
    }

    public function testAnErrorBodyIsAnExchangeError(): void
    {
        $this->expectException(ExchangeError::class);
        (new BinanceFunding())->parse('{"code":-1121,"msg":"Invalid symbol."}');
    }

    public function testTheCarryAddsUpTheFundingAndTakesOffOneRoundTrip(): void
    {
        // 30 days, three settlements a day: +0.01% each, except a 2-day negative stretch of -0.01%
        $rates = [];
        $time = 1_700_000_000_000;

        for ($i = 0; $i < 90; $i++) {
            $rates[] = new Rate($time + $i * 8 * 3_600_000, $i >= 30 && $i < 36 ? -0.0001 : 0.0001);
        }

        $s = Carry::summarize($rates, spotFeeRate: 0.001, perpFeeRate: 0.0005, slippage: 0.0005, marginRatio: 0.5);

        $this->assertSame(90, $s['settlements']);
        $this->assertEqualsWithDelta(84 * 0.0001 - 6 * 0.0001, $s['grossPct'], 1e-9);
        $this->assertEqualsWithDelta(2 * 0.0015 + 2 * 0.001, $s['roundTripPct'], 1e-9, 'four legs: spot in and out, perp in and out');
        $this->assertEqualsWithDelta($s['grossPct'] - $s['roundTripPct'], $s['netPct'], 1e-9);
        $this->assertEqualsWithDelta(3.0, $s['settlementsPerDay'], 0.11);
        $this->assertSame(6, $s['negativeSettlements']);
        $this->assertSame(6, $s['longestNegativeSettlements']);
        $this->assertEqualsWithDelta(2.0, $s['longestNegativeDays'], 0.1);
        $this->assertSame($rates[30]->time, $s['longestNegativeFrom']);
        $this->assertEqualsWithDelta($s['netAnnualizedPct'] / 1.5, $s['onCapitalAnnualizedPct'], 1e-9, 'spot plus half again as margin');
        $this->assertSame(0.0001, $s['medianRate']);
        $this->assertSame(0, $s['negativeMonths']);
    }

    public function testMonthsAreTotalledAndTheWorstNamed(): void
    {
        $rates = [
            new Rate(\PigQuant\Backtest::parseDate('2026-01-05'), 0.0003),
            new Rate(\PigQuant\Backtest::parseDate('2026-01-15'), 0.0002),
            new Rate(\PigQuant\Backtest::parseDate('2026-02-05'), -0.0004),
            new Rate(\PigQuant\Backtest::parseDate('2026-03-05'), 0.0001),
        ];

        $s = Carry::summarize($rates, 0.001, 0.0005, 0.0005, 0.5);

        $this->assertEqualsWithDelta(0.0005, $s['months']['2026-01'], 1e-12);
        $this->assertEqualsWithDelta(-0.0004, $s['months']['2026-02'], 1e-12);
        $this->assertSame(1, $s['negativeMonths']);
        $this->assertSame('2026-02', $s['worstMonth']);
        $this->assertSame('2026-01', $s['bestMonth']);

        $lines = Carry::lines($s, 'BTCUSDT');
        $this->assertStringStartsWith('BTCUSDT · 4 settlements', $lines[0]);
        $this->assertStringContainsString('1 negative of 3', $lines[4]);
        $this->assertStringContainsString('2026-02', $lines[5]);
        $this->assertStringContainsString('-0.04%', $lines[5]);
    }

    public function testNoSettlementsIsSaidPlainly(): void
    {
        $this->assertSame(['BTCUSDT: no funding settlements in the range.'], Carry::lines(Carry::summarize([], 0.001, 0.0005, 0.0005, 0.5), 'BTCUSDT'));
    }

    public function testTheCurrentRateParsesFromThePremiumIndex(): void
    {
        $request = (new BinanceFunding())->currentRequest('https://fapi.binance.com', 'eth-usdt');
        $this->assertSame('https://fapi.binance.com/fapi/v1/premiumIndex?symbol=ETHUSDT', $request->url);

        $current = (new BinanceFunding())->parseCurrent('{"symbol":"BTCUSDT","markPrice":"79600.10","indexPrice":"79590.00","estimatedSettlePrice":"79595.00","lastFundingRate":"0.00050000","interestRate":"0.00010000","nextFundingTime":1762416000000,"time":1762400000000}');
        $this->assertSame('BTCUSDT', $current['symbol']);
        $this->assertSame(0.0005, $current['rate']);
        $this->assertSame(1_762_416_000_000, $current['nextFundingTime']);
        $this->assertSame(79600.10, $current['markPrice']);

        $this->expectException(ExchangeError::class);
        (new BinanceFunding())->parseCurrent('{"code":-1121,"msg":"Invalid symbol."}');
    }

    public function testTheMonitorSaysOverOnceAndBackOnce(): void
    {
        $monitor = new Monitor(alertAnnualized: 0.15);

        $this->assertEqualsWithDelta(0.1095, $monitor->annualized(0.0001), 1e-9, '0.01% three times a day');
        $this->assertNull($monitor->update('BTCUSDT', 0.0001, 1000), 'under: nothing');

        $above = $monitor->update('BTCUSDT', 0.0002, 2000);
        $this->assertSame('above', $above['event']);
        $this->assertEqualsWithDelta(0.219, $above['annualized'], 1e-9);
        $this->assertSame(2000, $above['since']);
        $this->assertNull($monitor->update('BTCUSDT', 0.0003, 3000), 'still over: said once');
        $this->assertSame(['BTCUSDT' => 2000], $monitor->above());

        $back = $monitor->update('BTCUSDT', 0.0001, 4000);
        $this->assertSame('back', $back['event']);
        $this->assertSame(2000, $back['since'], 'since when it had been over');
        $this->assertSame([], $monitor->above());
        $this->assertNull($monitor->update('BTCUSDT', 0.0001, 5000));
        $this->assertNull($monitor->update('ETHUSDT', 0.00005, 5000), 'symbols are independent');
    }

    public function testArgsParse(): void
    {
        $spec = Funding::parseArgs('eth-usdt 2026-01-01 2026-02-01');

        $this->assertSame('eth-usdt', $spec['symbol']);
        $this->assertSame(\PigQuant\Backtest::parseDate('2026-01-01'), $spec['from']);
        $this->assertSame(\PigQuant\Backtest::parseDate('2026-02-01'), $spec['to']);

        $this->expectException(\InvalidArgumentException::class);
        Funding::parseArgs('BTC-USDT');
    }
}
