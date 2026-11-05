<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quant\Market\Candle;
use Quant\Spread\Observer;
use Quant\Spread\Sample;
use Quant\Spread\Stats;

final class SpreadTest extends TestCase
{
    private static function candle(int $minute, float $close, bool $closed): Candle
    {
        $open = $minute * 60_000;

        return new Candle($open, $close, $close, $close, $close, 1.0, $open + 59_999, $closed);
    }

    public function testBpsIsTheGapOverTheMid(): void
    {
        $this->assertEqualsWithDelta(10.0, Sample::bps(100.0, 100.1), 0.01, 'b dearer by 0.1%');
        $this->assertEqualsWithDelta(-10.0, Sample::bps(100.1, 100.0), 0.01);
        $this->assertSame(0.0, Sample::bps(0.0, 0.0));
    }

    public function testAMinuteIsEmittedOnceBothSidesHaveClosedItWithTheWidestGapSeen(): void
    {
        $observer = new Observer('binance', 'okx', 'BTCUSDT');
        $samples = [];
        $observer->onSample(static function (Sample $s) use (&$samples): void {
            $samples[] = $s;
        });

        $observer->onCandle('binance', self::candle(1, 100.0, false));
        $this->assertNull($observer->liveBps(), 'one side only');

        $observer->onCandle('okx', self::candle(1, 100.5, false)); // 50 bps apart for a moment
        $this->assertEqualsWithDelta(50.0, $observer->liveBps(), 0.2);

        $observer->onCandle('okx', self::candle(1, 100.1, true));
        $this->assertSame([], $samples, 'binance has not closed the minute');

        $observer->onCandle('binance', self::candle(1, 100.0, true));

        $this->assertCount(1, $samples);
        $this->assertSame(60_000, $samples[0]->time);
        $this->assertSame(100.0, $samples[0]->a);
        $this->assertSame(100.1, $samples[0]->b);
        $this->assertEqualsWithDelta(10.0, $samples[0]->bps, 0.01, 'the gap at the close');
        $this->assertEqualsWithDelta(50.0, $samples[0]->maxAbsBps, 0.2, 'the widest gap inside the minute');
        $this->assertSame(1, $observer->emitted());
    }

    public function testAMinuteOneSideNeverClosedIsLetGo(): void
    {
        $observer = new Observer('binance', 'okx', 'BTCUSDT');
        $count = 0;
        $observer->onSample(static function () use (&$count): void {
            $count++;
        });

        $observer->onCandle('binance', self::candle(1, 100.0, true)); // okx never sends minute 1
        $observer->onCandle('binance', self::candle(2, 101.0, true));
        $observer->onCandle('okx', self::candle(2, 101.2, true));

        $this->assertSame(1, $count, 'minute 2 only');

        $observer->onCandle('okx', self::candle(1, 100.0, true)); // late: minute 1 is gone
        $this->assertSame(1, $count);
    }

    public function testAnotherExchangeIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new Observer('binance', 'okx', 'BTCUSDT'))->onCandle('kraken', self::candle(1, 1.0, true));
    }

    public function testStatsCountTheMinutesAndStretchesOverTheThreshold(): void
    {
        $bps = [1.0, -2.0, 35.0, 40.0, 3.0, -31.0, 2.0, 50.0];
        $samples = [];

        foreach ($bps as $i => $value) {
            $samples[] = new Sample($i * 60_000, 100.0, 100.0 * (1 + $value / 10_000), $value, abs($value) + 5);
        }

        $stats = Stats::summarize($samples, 30.0);

        $this->assertSame(8, $stats['samples']);
        $this->assertSame(4, $stats['minutesAboveThreshold']);
        $this->assertSame(0.5, $stats['shareAboveThreshold']);
        $this->assertSame(3, $stats['runsAboveThreshold'], '[35, 40], [-31], [50]');
        $this->assertSame(2, $stats['longestRunMinutes']);
        $this->assertSame(50.0, $stats['maxAbsBps']);
        $this->assertSame(55.0, $stats['maxIntraMinuteBps']);
        $this->assertEqualsWithDelta(0.75, $stats['bSideDearerShare'], 0.001);
        $this->assertSame(3.0, $stats['p50AbsBps']);

        $this->assertSame(['samples' => 0, 'thresholdBps' => 30.0], Stats::summarize([], 30.0));
        $this->assertStringContainsString('no complete minutes', Stats::lines(Stats::summarize([], 30.0), 'binance', 'okx', 'BTCUSDT')[0]);
        $this->assertCount(4, Stats::lines($stats, 'binance', 'okx', 'BTCUSDT'));
    }
}
