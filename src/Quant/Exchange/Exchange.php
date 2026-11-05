<?php

declare(strict_types=1);

namespace Quant\Exchange;

use Quant\Market\Candle;
use Quant\Transport\HttpRequest;

/**
 * An exchange, as the engine sees it: how to ask for candles, how to read the answer, which
 * stream carries live candles and how to read its messages. Pure functions over strings — no
 * sockets, no clock — so an exchange is tested from recorded payloads, and the host decides how
 * the bytes travel.
 *
 * Adding an exchange is one class implementing this. Spot candles only, for now: orders stay in
 * `DryRunWallet` until real trading exists.
 */
interface Exchange
{
    public function name(): string;

    /** Normalize a symbol the person typed (`btc/usdt`, `BTC-USDT`) to the exchange's own form. */
    public function symbol(string $symbol): string;

    /**
     * @param int|null $startTime Unix milliseconds; candles whose open time is at or after it
     * @param int|null $endTime Unix milliseconds; candles whose open time is before it (exclusive)
     */
    public function candlesRequest(string $symbol, string $interval, int $limit, ?int $startTime = null, ?int $endTime = null): HttpRequest;

    /** The most candles one `candlesRequest()` with a time range may ask for. */
    public function candlesPerRequest(): int;

    /**
     * Which end of a time range a page comes from. False (Binance): the oldest `limit` candles
     * from `startTime` on, so a range is walked forward. True (OKX): the newest `limit` candles
     * before `endTime`, so a range is walked backward.
     */
    public function pagesNewestFirst(): bool;

    /**
     * The candles in a `candlesRequest()` response body, oldest first. `$interval` is the one
     * asked for: an exchange whose rows carry no close time works it out from the open time.
     *
     * @return list<Candle>
     * @throws ExchangeError when the body is not what the exchange sends for candles (an error
     *         object, a geo-block page, a truncated transfer)
     */
    public function parseCandles(string $body, string $interval): array;

    /** @param list<string> $symbols */
    public function streamUrl(array $symbols, string $interval): string;

    /**
     * Messages to send once the stream is open, in order — a subscription, say. Empty for an
     * exchange whose URL names the streams.
     *
     * @param list<string> $symbols
     * @return list<string>
     */
    public function streamSubscriptions(array $symbols, string $interval): array;

    /**
     * What to send, and how often, to keep the stream alive — `['every' => seconds, 'message' =>
     * text]` — or null for an exchange that pings on its own (the host answers protocol pings).
     *
     * @return array{every: float, message: string}|null
     */
    public function streamHeartbeat(): ?array;

    /**
     * A candle update in a stream message, or null for a message that is not one (subscription
     * acknowledgements, pings the host did not answer, other channels).
     *
     * @throws ExchangeError when the message is an error the exchange reports
     */
    public function parseStreamMessage(string $message): ?StreamUpdate;
}
