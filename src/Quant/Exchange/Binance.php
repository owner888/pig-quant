<?php

declare(strict_types=1);

namespace Quant\Exchange;

use Quant\Market\Candle;
use Quant\Transport\HttpRequest;

/**
 * Binance spot: `GET /api/v3/klines` for history and the combined stream
 * `/stream?streams=<symbol>@kline_<interval>/…` for live candles.
 *
 * Both endpoints are overridable: Binance keeps several hosts (`api1`–`api4`, `data-api.binance.vision`
 * for market data without a key, the spot testnet), and the person picks one in the settings.
 */
final class Binance implements Exchange
{
    public const string REST = 'https://api.binance.com';

    public const string STREAM = 'wss://stream.binance.com:9443';

    public const array INTERVALS = ['1s', '1m', '3m', '5m', '15m', '30m', '1h', '2h', '4h', '6h', '8h', '12h', '1d', '3d', '1w', '1M'];

    public function __construct(
        private readonly string $rest = self::REST,
        private readonly string $stream = self::STREAM,
    ) {
    }

    public function name(): string
    {
        return 'binance';
    }

    public function symbol(string $symbol): string
    {
        return strtoupper(str_replace(['/', '-', '_', ' '], '', $symbol));
    }

    public function candlesRequest(string $symbol, string $interval, int $limit): HttpRequest
    {
        self::checkInterval($interval);
        $limit = max(1, min(1000, $limit));
        $query = http_build_query(['symbol' => $this->symbol($symbol), 'interval' => $interval, 'limit' => $limit]);

        return new HttpRequest('GET', rtrim($this->rest, '/') . '/api/v3/klines?' . $query);
    }

    public function parseCandles(string $body): array
    {
        $rows = json_decode($body, true);

        if (!is_array($rows)) {
            throw new ExchangeError('Binance did not answer with JSON for klines: ' . self::excerpt($body));
        }

        if (isset($rows['code'], $rows['msg'])) {
            throw new ExchangeError("Binance refused the klines request ({$rows['code']}): {$rows['msg']}");
        }

        $candles = [];

        foreach ($rows as $row) {
            if (!is_array($row) || count($row) < 7) {
                throw new ExchangeError('Binance sent a kline row that is not the documented 12-field array: ' . self::excerpt(json_encode($row)));
            }

            $candles[] = new Candle(
                openTime: (int) $row[0],
                open: (float) $row[1],
                high: (float) $row[2],
                low: (float) $row[3],
                close: (float) $row[4],
                volume: (float) $row[5],
                closeTime: (int) $row[6],
                closed: true,
            );
        }

        return $candles;
    }

    public function streamUrl(array $symbols, string $interval): string
    {
        self::checkInterval($interval);

        if ($symbols === []) {
            throw new ExchangeError('A stream needs at least one symbol.');
        }

        $streams = array_map(fn (string $s): string => strtolower($this->symbol($s)) . '@kline_' . $interval, $symbols);

        return rtrim($this->stream, '/') . '/stream?streams=' . implode('/', $streams);
    }

    public function parseStreamMessage(string $message): ?StreamUpdate
    {
        $decoded = json_decode($message, true);

        if (!is_array($decoded)) {
            throw new ExchangeError('Binance stream sent something that is not JSON: ' . self::excerpt($message));
        }

        if (isset($decoded['error'])) {
            $error = $decoded['error'];
            $text = is_array($error) ? (($error['code'] ?? '?') . ': ' . ($error['msg'] ?? '')) : (string) $error;

            throw new ExchangeError("Binance stream error {$text}");
        }

        // Combined streams wrap each event as {"stream": …, "data": {…}}; a raw stream sends the event itself.
        $event = $decoded['data'] ?? $decoded;

        if (($event['e'] ?? null) !== 'kline' || !isset($event['k']) || !is_array($event['k'])) {
            return null;
        }

        $k = $event['k'];

        return new StreamUpdate(
            symbol: (string) $k['s'],
            interval: (string) $k['i'],
            candle: new Candle(
                openTime: (int) $k['t'],
                open: (float) $k['o'],
                high: (float) $k['h'],
                low: (float) $k['l'],
                close: (float) $k['c'],
                volume: (float) $k['v'],
                closeTime: (int) $k['T'],
                closed: (bool) $k['x'],
            ),
        );
    }

    private static function checkInterval(string $interval): void
    {
        if (!in_array($interval, self::INTERVALS, true)) {
            throw new ExchangeError("Binance has no '{$interval}' interval; it has " . implode(', ', self::INTERVALS) . '.');
        }
    }

    private static function excerpt(string|false $text): string
    {
        $text = (string) $text;

        return strlen($text) > 200 ? substr($text, 0, 200) . '…' : $text;
    }
}
