<?php

declare(strict_types=1);

namespace Quant\Test\Unit;

use PHPUnit\Framework\TestCase;
use Quant\Exchange\Binance;
use Quant\Exchange\ExchangeError;

final class BinanceTest extends TestCase
{
    public function testTheCandlesRequestIsTheDocumentedKlinesCall(): void
    {
        $request = (new Binance())->candlesRequest('btc/usdt', '1m', 5000);

        $this->assertSame('GET', $request->method);
        $this->assertSame('https://api.binance.com/api/v3/klines?symbol=BTCUSDT&interval=1m&limit=1000', $request->url);
    }

    public function testAnUnknownIntervalIsRefusedBeforeAnyRequest(): void
    {
        $this->expectException(ExchangeError::class);
        (new Binance())->candlesRequest('BTCUSDT', '7m', 10);
    }

    public function testKlineRowsBecomeCandles(): void
    {
        $body = json_encode([
            [1700000000000, '100.0', '110.0', '90.0', '105.0', '12.5', 1700000059999, '1300.0', 10, '6.0', '630.0', '0'],
            [1700000060000, '105.0', '106.0', '101.0', '102.0', '3.0', 1700000119999, '310.0', 4, '1.0', '103.0', '0'],
        ]);

        $candles = (new Binance())->parseCandles($body);

        $this->assertCount(2, $candles);
        $this->assertSame(1700000000000, $candles[0]->openTime);
        $this->assertSame(105.0, $candles[0]->close);
        $this->assertSame(12.5, $candles[0]->volume);
        $this->assertSame(1700000119999, $candles[1]->closeTime);
        $this->assertTrue($candles[1]->closed);
    }

    public function testAnErrorBodyIsAnExchangeError(): void
    {
        $this->expectException(ExchangeError::class);
        $this->expectExceptionMessage('-1121');
        (new Binance())->parseCandles('{"code":-1121,"msg":"Invalid symbol."}');
    }

    public function testAGeoBlockPageIsNotMistakenForCandles(): void
    {
        $this->expectException(ExchangeError::class);
        (new Binance())->parseCandles('<html><body>Service unavailable from a restricted location</body></html>');
    }

    public function testTheStreamUrlCombinesTheSymbols(): void
    {
        $url = (new Binance())->streamUrl(['BTCUSDT', 'eth-usdt'], '5m');

        $this->assertSame('wss://stream.binance.com:9443/stream?streams=btcusdt@kline_5m/ethusdt@kline_5m', $url);
    }

    public function testACombinedStreamKlineBecomesAnUpdate(): void
    {
        $message = json_encode(['stream' => 'btcusdt@kline_1m', 'data' => [
            'e' => 'kline', 'E' => 1700000030000, 's' => 'BTCUSDT',
            'k' => ['t' => 1700000000000, 'T' => 1700000059999, 's' => 'BTCUSDT', 'i' => '1m', 'o' => '100', 'c' => '101.5', 'h' => '102', 'l' => '99', 'v' => '7', 'x' => false],
        ]]);

        $update = (new Binance())->parseStreamMessage($message);

        $this->assertNotNull($update);
        $this->assertSame('BTCUSDT', $update->symbol);
        $this->assertSame('1m', $update->interval);
        $this->assertSame(101.5, $update->candle->close);
        $this->assertFalse($update->candle->closed);
    }

    public function testOtherMessagesAreIgnoredAndErrorsThrow(): void
    {
        $binance = new Binance();

        $this->assertNull($binance->parseStreamMessage('{"result":null,"id":1}'));
        $this->assertNull($binance->parseStreamMessage('{"stream":"btcusdt@trade","data":{"e":"trade","p":"1"}}'));

        $this->expectException(ExchangeError::class);
        $binance->parseStreamMessage('{"error":{"code":2,"msg":"Invalid request"}}');
    }

    public function testEndpointsCanBeOverridden(): void
    {
        $binance = new Binance('https://data-api.binance.vision/', 'wss://testnet.binance.vision/');

        $this->assertStringStartsWith('https://data-api.binance.vision/api/v3/klines?', $binance->candlesRequest('BTCUSDT', '1h', 10)->url);
        $this->assertStringStartsWith('wss://testnet.binance.vision/stream?', $binance->streamUrl(['BTCUSDT'], '1h'));
    }
}
