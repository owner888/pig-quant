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

    public function candlesRequest(string $symbol, string $interval, int $limit): HttpRequest;

    /**
     * The candles in a `candlesRequest()` response body, oldest first.
     *
     * @return list<Candle>
     * @throws ExchangeError when the body is not what the exchange sends for candles (an error
     *         object, a geo-block page, a truncated transfer)
     */
    public function parseCandles(string $body): array;

    /** @param list<string> $symbols */
    public function streamUrl(array $symbols, string $interval): string;

    /**
     * A candle update in a stream message, or null for a message that is not one (subscription
     * acknowledgements, pings the host did not answer, other channels).
     *
     * @throws ExchangeError when the message is an error the exchange reports
     */
    public function parseStreamMessage(string $message): ?StreamUpdate;
}
