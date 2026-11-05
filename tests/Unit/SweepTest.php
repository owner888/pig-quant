<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quant\Backtest\Backtester;
use Quant\Backtest\Report;
use Quant\Backtest\Sweep;
use Quant\Engine;
use Quant\Exchange\Binance;
use Quant\Market\Candle;
use Quant\Risk\RiskRules;
use Quant\Strategy\MovingAverageCross;
use Quant\Wallet\DryRunWallet;

final class SweepTest extends TestCase
{
    public function testARangeExpandsFromStartToEndByStep(): void
    {
        $this->assertSame([10, 15, 20, 25, 30], Sweep::values('fast', '10..30:5'));
        $this->assertSame([10, 11, 12], Sweep::values('fast', '10..12'), 'the step defaults to 1');
        $this->assertSame([10, 15, 20], Sweep::values('fast', '10..22:5'), 'an end off the step is not reached');
        $this->assertSame([30, 20, 10], Sweep::values('fast', '30..10:-10'), 'downwards with a negative step');
    }

    public function testAFloatRangeDoesNotDrift(): void
    {
        $this->assertSame([0.1, 0.2, 0.3], Sweep::values('stopLossPct', '0.1..0.3:0.1'));
        $this->assertSame([0.01, 0.02, 0.03, 0.04, 0.05], Sweep::values('stopLossPct', '0.01..0.05:0.01'));
    }

    public function testAListAndAPlainNumberAreValuesToo(): void
    {
        $this->assertSame([5, 10, 20], Sweep::values('slow', '5,10,20'));
        $this->assertSame([0.02], Sweep::values('stopLossPct', '0.02'));
        $this->assertSame([2.5, 3], Sweep::values('x', '2.5, 3'));
    }

    public function testABadRangeSaysWhatIsWanted(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('towards the end');
        Sweep::values('fast', '10..30:-5');
    }

    public function testWordsAreRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('fast is a number');
        Sweep::values('fast', 'ten');
    }

    public function testCombinationsVaryTheFirstNameSlowestAndCarryTheFixedOnes(): void
    {
        $combinations = Sweep::combinations(['fast' => [10, 20], 'slow' => [30, 50]], ['stopLossPct' => 0.1]);

        $this->assertCount(4, $combinations);
        $this->assertSame(['stopLossPct' => 0.1, 'fast' => 10, 'slow' => 30], $combinations[0]);
        $this->assertSame(['stopLossPct' => 0.1, 'fast' => 10, 'slow' => 50], $combinations[1]);
        $this->assertSame(['stopLossPct' => 0.1, 'fast' => 20, 'slow' => 30], $combinations[2]);
    }

    public function testTooManyCombinationsAreRefusedBeforeAnythingRuns(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('500');
        Sweep::combinations(['a' => range(1, 30), 'b' => range(1, 30)]);
    }

    public function testSplitSeparatesRangesFromSingleValues(): void
    {
        $split = Sweep::split(['fast' => '10..20:10', 'slow' => '30,50', 'stopLossPct' => '0.1']);

        $this->assertSame(['fast' => [10, 20], 'slow' => [30, 50]], $split['sweeps']);
        $this->assertSame(['stopLossPct' => 0.1], $split['fixed']);
    }

    public function testTwoSweptParametersDrawAGrid(): void
    {
        $results = [];

        foreach ([[2, 3], [2, 4], [3, 4]] as [$fast, $slow]) {
            $results[] = ['overrides' => ['fast' => $fast, 'slow' => $slow], 'report' => self::report($fast, $slow)];
        }

        $lines = Sweep::lines($results, ['fast' => [2, 3], 'slow' => [3, 4]]);

        $this->assertStringContainsString('3 runs', $lines[0]);
        $this->assertStringContainsString('buy & hold', $lines[0]);
        $this->assertStringStartsWith('fast\\slow', $lines[1]);
        $this->assertStringContainsString('3', $lines[1]);
        $this->assertStringContainsString('4', $lines[1]);
        $this->assertStringStartsWith('2 ', $lines[2]);
        $this->assertStringStartsWith('3 ', $lines[3]);
        $this->assertStringContainsString('—', $lines[3], 'fast=3 slow=3 did not run');
        $this->assertStringContainsString('beat buy & hold', $lines[count($lines) - 1]);
    }

    public function testOneSweptParameterListsRunsBestFirst(): void
    {
        $results = [
            ['overrides' => ['fast' => 2, 'slow' => 3], 'report' => self::report(2, 3)],
            ['overrides' => ['fast' => 2, 'slow' => 4], 'report' => self::report(2, 4)],
        ];
        $best = $results[0]['report']->returnPct >= $results[1]['report']->returnPct ? 'slow=3' : 'slow=4';

        $lines = Sweep::lines($results, ['slow' => [3, 4]]);

        $this->assertCount(4, $lines);
        $this->assertStringStartsWith($best, $lines[1]);
        $this->assertStringContainsString('PF', $lines[1]);
        $this->assertStringContainsString('closed', $lines[1]);
    }

    public function testBuyAndHoldHasItsOwnDrawdown(): void
    {
        $report = self::report(2, 3);

        // closes 12, 12, 12, 5, 5 after an open of 10: the peak is 12, the trough 5
        $this->assertEqualsWithDelta(1 - 5.0 / 12.0, $report->buyAndHoldDrawdownPct, 0.001);
        $this->assertStringContainsString('max drawdown 58.33%', $report->lines()[1]);
    }

    private static function report(int $fast, int $slow): Report
    {
        $engine = new Engine(new Binance(), new MovingAverageCross($fast, $slow), new DryRunWallet('USDT', 1000.0, 0.0, 0.0), new RiskRules(100.0, 500.0, 1, 0.0), ['BTCUSDT'], '1m', 100.0);
        $candles = static function (array $closes, int $first = 0): array {
            $out = [];

            foreach ($closes as $i => $close) {
                $open = ($first + $i) * 60_000;
                $out[] = new Candle($open, $i === 0 ? 10.0 : $close, $close, $close, $close, 1.0, $open + 59_999, true);
            }

            return $out;
        };

        return Backtester::run($engine, 'BTCUSDT', $candles([10.0, 9.0, 8.0, 7.0]), $candles([12.0, 12.0, 12.0, 5.0, 5.0], 4));
    }
}
