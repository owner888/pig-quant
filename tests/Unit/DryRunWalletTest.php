<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quant\Market\Side;
use Quant\Wallet\DryRunWallet;
use Quant\Wallet\WalletError;

final class DryRunWalletTest extends TestCase
{
    public function testABuyPaysSlippageAndFeeAndASellRealizesTheDifference(): void
    {
        $wallet = new DryRunWallet('USDT', 1000.0, 0.001, 0.0);

        $buy = $wallet->buy('BTCUSDT', 100.0, 50_000.0, 1, 'test');

        $this->assertSame(Side::Buy, $buy->side);
        $this->assertEqualsWithDelta(0.1, $buy->fee, 1e-9);
        $this->assertEqualsWithDelta(99.9 / 50_000, $buy->quantity, 1e-12);
        $this->assertEqualsWithDelta(900.0, $wallet->quote(), 1e-9);
        $this->assertEqualsWithDelta(99.9, $wallet->exposure(), 1e-9);

        $sell = $wallet->sell('BTCUSDT', null, 55_000.0, 2, 'test');

        $this->assertNull($wallet->position('BTCUSDT'));
        $this->assertNotNull($sell->pnl);
        $this->assertEqualsWithDelta($sell->quoteAmount - 99.9, $sell->pnl, 1e-9);
        $this->assertEqualsWithDelta(900.0 + $sell->quoteAmount, $wallet->quote(), 1e-9);
        $this->assertEqualsWithDelta($sell->pnl, $wallet->realizedPnl(), 1e-9);
        $this->assertCount(2, $wallet->trades());
    }

    public function testSlippageMovesTheFillAgainstTheTrader(): void
    {
        $wallet = new DryRunWallet('USDT', 1000.0, 0.0, 0.01);

        $buy = $wallet->buy('ETHUSDT', 100.0, 2_000.0, 1, 'test');
        $this->assertEqualsWithDelta(2_020.0, $buy->price, 1e-9);

        $sell = $wallet->sell('ETHUSDT', null, 2_000.0, 2, 'test');
        $this->assertEqualsWithDelta(1_980.0, $sell->price, 1e-9);
        $this->assertLessThan(0, $sell->pnl);
    }

    public function testABuyAveragesIntoAnOpenPosition(): void
    {
        $wallet = new DryRunWallet('USDT', 1000.0, 0.0, 0.0);
        $wallet->buy('BTCUSDT', 100.0, 100.0, 1, 'test');
        $wallet->buy('BTCUSDT', 100.0, 200.0, 2, 'test');

        $position = $wallet->position('BTCUSDT');
        $this->assertNotNull($position);
        $this->assertEqualsWithDelta(1.5, $position->quantity, 1e-12);
        $this->assertEqualsWithDelta(200.0 / 1.5, $position->averagePrice, 1e-9);
        $this->assertSame(1, $position->openedAt);
    }

    public function testItRefusesWhatItDoesNotHave(): void
    {
        $wallet = new DryRunWallet('USDT', 50.0, 0.0, 0.0);

        $this->expectException(WalletError::class);
        $wallet->buy('BTCUSDT', 100.0, 1.0, 1, 'test');
    }

    public function testSellingMoreThanHeldIsRefused(): void
    {
        $wallet = new DryRunWallet('USDT', 1000.0, 0.0, 0.0);
        $wallet->buy('BTCUSDT', 100.0, 100.0, 1, 'test');

        $this->expectException(WalletError::class);
        $wallet->sell('BTCUSDT', 2.0, 100.0, 2, 'test');
    }

    public function testStateRoundTrips(): void
    {
        $wallet = new DryRunWallet('USDT', 1000.0, 0.001, 0.0005);
        $wallet->buy('BTCUSDT', 100.0, 50_000.0, 1, 'test');
        $wallet->buy('ETHUSDT', 100.0, 2_000.0, 2, 'test');
        $wallet->sell('ETHUSDT', null, 2_100.0, 3, 'test');

        $copy = new DryRunWallet('USDT', 1000.0, 0.001, 0.0005);
        $copy->restore(json_decode(json_encode($wallet->toArray()), true));

        $this->assertEqualsWithDelta($wallet->quote(), $copy->quote(), 1e-12);
        $this->assertEqualsWithDelta($wallet->realizedPnl(), $copy->realizedPnl(), 1e-12);
        $this->assertEquals($wallet->position('BTCUSDT'), $copy->position('BTCUSDT'));
        $this->assertEquals($wallet->trades(), $copy->trades());
    }

    public function testRestoringAnotherQuoteAssetIsRefused(): void
    {
        $wallet = new DryRunWallet('USDT', 1000.0, 0.0, 0.0);

        $this->expectException(WalletError::class);
        $wallet->restore(['quoteAsset' => 'BUSD']);
    }
}
