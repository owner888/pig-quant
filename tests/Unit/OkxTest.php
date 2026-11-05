<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quant\Exchange\ExchangeError;
use Quant\Exchange\Okx;

final class OkxTest extends TestCase
{
    public function testSymbolsAreSpelledWithADash(): void
    {
        $okx = new Okx();

        $this->assertSame('BTC-USDT', $okx->symbol('BTC-USDT'));
        $this->assertSame('BTC-USDT', $okx->symbol('btc/usdt'));
        $this->assertSame('BTC-USDT', $okx->symbol('BTCUSDT'));
        $this->assertSame('ETH-BTC', $okx->symbol('ETHBTC'));

        $this->expectException(ExchangeError::class);
        $okx->symbol('BTCXYZ');
    }

    public function testTheLatestCandlesComeFromCandlesAndARangeFromHistoryPagedBackward(): void
    {
        $okx = new Okx();

        $latest = $okx->candlesRequest('BTCUSDT', '1h', 200)->url;
        $this->assertSame('https://www.okx.com/api/v5/market/candles?instId=BTC-USDT&bar=1H&limit=200', $latest);

        $range = $okx->candlesRequest('BTC-USDT', '1m', 500, 1_700_000_000_000, 1_700_003_600_000)->url;
        $this->assertStringStartsWith('https://www.okx.com/api/v5/market/history-candles?', $range);
        $this->assertStringContainsString('limit=100', $range, 'history pages are 100');
        $this->assertStringContainsString('after=1700003600000', $range, 'the exclusive end, as `after` is exclusive');
        $this->assertStringContainsString('before=1699999999999', $range);
        $this->assertTrue($okx->pagesNewestFirst());
        $this->assertSame(100, $okx->candlesPerRequest());
    }

    public function testAnUnknownIntervalIsRefused(): void
    {
        $this->expectException(ExchangeError::class);
        (new Okx())->candlesRequest('BTC-USDT', '7m', 10);
    }

    public function testRowsAreTurnedOldestFirstWithACloseTimeAndTheConfirmFlag(): void
    {
        $body = json_encode(['code' => '0', 'msg' => '', 'data' => [
            ['1700000060000', '105', '106', '101', '102', '3', '310', '310', '0'],
            ['1700000000000', '100', '110', '90', '105', '12.5', '1300', '1300', '1'],
        ]]);

        $candles = (new Okx())->parseCandles($body, '1m');

        $this->assertCount(2, $candles);
        $this->assertSame(1700000000000, $candles[0]->openTime, 'oldest first');
        $this->assertSame(1700000059999, $candles[0]->closeTime);
        $this->assertTrue($candles[0]->closed);
        $this->assertSame(102.0, $candles[1]->close);
        $this->assertFalse($candles[1]->closed, 'confirm 0');
    }

    public function testAnErrorCodeIsAnExchangeError(): void
    {
        $this->expectException(ExchangeError::class);
        $this->expectExceptionMessage('51001');
        (new Okx())->parseCandles('{"code":"51001","msg":"Instrument ID does not exist","data":[]}', '1m');
    }

    public function testTheStreamIsSubscribedToByMessageAndKeptAliveWithPing(): void
    {
        $okx = new Okx();

        $this->assertSame('wss://ws.okx.com:8443/ws/v5/business', $okx->streamUrl(['BTC-USDT'], '1m'));
        $this->assertSame(
            ['{"op":"subscribe","args":[{"channel":"candle1m","instId":"BTC-USDT"},{"channel":"candle1m","instId":"ETH-USDT"}]}'],
            $okx->streamSubscriptions(['btcusdt', 'ETH/USDT'], '1m'),
        );
        $this->assertSame(['every' => 20.0, 'message' => 'ping'], $okx->streamHeartbeat());
    }

    public function testStreamMessagesBecomeUpdatesAndTheRestIsIgnoredOrThrown(): void
    {
        $okx = new Okx();

        $this->assertNull($okx->parseStreamMessage('pong'));
        $this->assertNull($okx->parseStreamMessage('{"event":"subscribe","arg":{"channel":"candle1m","instId":"BTC-USDT"},"connId":"a"}'));

        $update = $okx->parseStreamMessage(json_encode(['arg' => ['channel' => 'candle1m', 'instId' => 'BTC-USDT'], 'data' => [
            ['1700000000000', '100', '102', '99', '101.5', '7', '700', '700', '0'],
        ]]));

        $this->assertNotNull($update);
        $this->assertSame('BTC-USDT', $update->symbol);
        $this->assertSame('1m', $update->interval);
        $this->assertSame(101.5, $update->candle->close);
        $this->assertSame(1700000059999, $update->candle->closeTime);
        $this->assertFalse($update->candle->closed);

        $this->expectException(ExchangeError::class);
        $okx->parseStreamMessage('{"event":"error","code":"60012","msg":"Invalid request"}');
    }
}
