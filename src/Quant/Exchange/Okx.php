<?php

declare(strict_types=1);

namespace Quant\Exchange;

use Quant\Market\Candle;
use Quant\Market\Interval;
use Quant\Transport\HttpRequest;

/**
 * OKX spot: `GET /api/v5/market/candles` for the latest candles, `GET /api/v5/market/history-candles`
 * for a time range (it reaches back years; `candles` only the last ~1,440), and the `candle<bar>`
 * channel on the business WebSocket for live ones.
 *
 * Where it differs from Binance, and the interface grew for it:
 *
 * - Symbols are `BTC-USDT`; bars are `1m … 1H, 4H, 1D` with the hour and up in capitals.
 * - A response is `{"code": "0", "data": [...]}` with the candles **newest first**, each
 *   `[ts, o, h, l, c, vol, volCcy, volCcyQuote, confirm]`; a non-zero `code` is an error.
 * - A time range is paged **backward**: `after=<ts>` is "records earlier than ts", and `before`
 *   on its own answers the latest data rather than the oldest after it. 100 a page.
 * - The stream is subscribed to with a message after connecting, and kept alive with a `ping`
 *   text every so often (OKX closes a connection silent for 30 s); it answers `pong`.
 */
final class Okx implements Exchange
{
    public const string REST = 'https://www.okx.com';

    public const string STREAM = 'wss://ws.okx.com:8443';

    /** Bars as OKX spells them, by the interval as pig spells it. */
    private const array BARS = [
        '1m' => '1m', '3m' => '3m', '5m' => '5m', '15m' => '15m', '30m' => '30m',
        '1h' => '1H', '2h' => '2H', '4h' => '4H', '6h' => '6H', '12h' => '12H',
        '1d' => '1D', '1w' => '1W', '1M' => '1M',
    ];

    public function __construct(
        private readonly string $rest = self::REST,
        private readonly string $stream = self::STREAM,
    ) {
    }

    public function name(): string
    {
        return 'okx';
    }

    /** `btcusdt`, `BTC/USDT`, `BTC_USDT` → `BTC-USDT`; a bare `BTCUSDT` is split before a known quote. */
    public function symbol(string $symbol): string
    {
        $upper = strtoupper(trim($symbol));
        $joined = str_replace(['/', '_', ' '], '-', $upper);

        if (str_contains($joined, '-')) {
            return $joined;
        }

        foreach (['USDT', 'USDC', 'USD', 'BTC', 'ETH', 'EUR'] as $quote) {
            if (str_ends_with($joined, $quote) && strlen($joined) > strlen($quote)) {
                return substr($joined, 0, -strlen($quote)) . '-' . $quote;
            }
        }

        throw new ExchangeError("'{$symbol}' is not a symbol OKX knows how to spell; write BTC-USDT.");
    }

    public function candlesPerRequest(): int
    {
        return 100;
    }

    public function pagesNewestFirst(): bool
    {
        return true;
    }

    public function candlesRequest(string $symbol, string $interval, int $limit, ?int $startTime = null, ?int $endTime = null): HttpRequest
    {
        $bar = self::bar($interval);
        $base = rtrim($this->rest, '/');

        if ($startTime === null && $endTime === null) {
            $query = http_build_query(['instId' => $this->symbol($symbol), 'bar' => $bar, 'limit' => max(1, min(300, $limit))]);

            return new HttpRequest('GET', "{$base}/api/v5/market/candles?{$query}");
        }

        // `after` is exclusive, "earlier than": the interface's exclusive end is passed as it is.
        // `before` on its own would answer the latest data, so it is only ever sent with `after`.
        $query = http_build_query(array_filter([
            'instId' => $this->symbol($symbol),
            'bar' => $bar,
            'limit' => max(1, min($this->candlesPerRequest(), $limit)),
            'after' => $endTime,
            'before' => $startTime === null ? null : $startTime - 1,
        ], static fn ($v) => $v !== null));

        return new HttpRequest('GET', "{$base}/api/v5/market/history-candles?{$query}");
    }

    public function parseCandles(string $body, string $interval): array
    {
        $decoded = json_decode($body, true);

        if (!is_array($decoded) || !isset($decoded['code'])) {
            throw new ExchangeError('OKX did not answer with its JSON envelope for candles: ' . self::excerpt($body));
        }

        if ((string) $decoded['code'] !== '0') {
            throw new ExchangeError("OKX refused the candles request ({$decoded['code']}): " . ($decoded['msg'] ?? ''));
        }

        $rows = $decoded['data'] ?? null;

        if (!is_array($rows)) {
            throw new ExchangeError('OKX sent no data array for candles: ' . self::excerpt($body));
        }

        self::bar($interval);
        $candles = [];

        foreach ($rows as $row) {
            $candles[] = self::candle($row, $interval);
        }

        // Newest first from OKX; oldest first for the engine.
        return array_reverse($candles);
    }

    public function streamUrl(array $symbols, string $interval): string
    {
        self::bar($interval);

        if ($symbols === []) {
            throw new ExchangeError('A stream needs at least one symbol.');
        }

        return rtrim($this->stream, '/') . '/ws/v5/business';
    }

    public function streamSubscriptions(array $symbols, string $interval): array
    {
        $bar = self::bar($interval);
        $args = array_map(fn (string $s): array => ['channel' => "candle{$bar}", 'instId' => $this->symbol($s)], $symbols);

        return [(string) json_encode(['op' => 'subscribe', 'args' => $args])];
    }

    public function streamHeartbeat(): ?array
    {
        return ['every' => 20.0, 'message' => 'ping'];
    }

    public function parseStreamMessage(string $message): ?StreamUpdate
    {
        if ($message === 'pong') {
            return null;
        }

        $decoded = json_decode($message, true);

        if (!is_array($decoded)) {
            throw new ExchangeError('OKX stream sent something that is not JSON: ' . self::excerpt($message));
        }

        if (($decoded['event'] ?? null) === 'error') {
            throw new ExchangeError('OKX stream error ' . ($decoded['code'] ?? '?') . ': ' . ($decoded['msg'] ?? ''));
        }

        $channel = $decoded['arg']['channel'] ?? null;

        if (!is_string($channel) || !str_starts_with($channel, 'candle') || !isset($decoded['data'][0]) || !is_array($decoded['data'][0])) {
            return null; // a subscribe acknowledgement, or another channel
        }

        $bar = substr($channel, strlen('candle'));
        $interval = array_search($bar, self::BARS, true);

        if ($interval === false) {
            throw new ExchangeError("OKX stream sent a bar pig does not know: {$bar}");
        }

        // One message carries the latest state of one candle; several only when the stream catches up.
        $row = $decoded['data'][count($decoded['data']) - 1];

        return new StreamUpdate(
            symbol: (string) ($decoded['arg']['instId'] ?? ''),
            interval: $interval,
            candle: self::candle($row, $interval),
        );
    }

    /** @param list<mixed> $row `[ts, o, h, l, c, vol, volCcy, volCcyQuote, confirm]` */
    private static function candle(mixed $row, string $interval): Candle
    {
        if (!is_array($row) || count($row) < 9) {
            throw new ExchangeError('OKX sent a candle row that is not the documented 9-field array: ' . self::excerpt(json_encode($row)));
        }

        $open = (int) $row[0];

        return new Candle(
            openTime: $open,
            open: (float) $row[1],
            high: (float) $row[2],
            low: (float) $row[3],
            close: (float) $row[4],
            volume: (float) $row[5],
            closeTime: $open + Interval::milliseconds($interval) - 1,
            closed: (string) $row[8] === '1',
        );
    }

    private static function bar(string $interval): string
    {
        return self::BARS[$interval] ?? throw new ExchangeError("OKX has no '{$interval}' interval; it has " . implode(', ', array_keys(self::BARS)) . '.');
    }

    private static function excerpt(string|false $text): string
    {
        $text = (string) $text;

        return strlen($text) > 200 ? substr($text, 0, 200) . '…' : $text;
    }
}
