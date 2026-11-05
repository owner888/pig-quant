<?php

declare(strict_types=1);

namespace Quant\Funding;

use Quant\Exchange\ExchangeError;
use Quant\Transport\HttpRequest;

/**
 * Binance USDⓈ-M perpetual funding history: `GET /fapi/v1/fundingRate`, up to 1000 settlements
 * a page, oldest first, from 2019 — the one public venue with the whole history (OKX serves
 * three months). A funding rate is a market's own number, so the figures stand for what a
 * carry would have earned on that market; other venues' rates track it closely but not exactly.
 */
final class BinanceFunding
{
    public const string REST = 'https://fapi.binance.com';

    public const int PER_REQUEST = 1000;

    public function name(): string
    {
        return 'binance';
    }

    /** `BTC-USDT` or `btcusdt` → `BTCUSDT`, the perpetual's symbol. */
    public function symbol(string $symbol): string
    {
        return strtoupper(str_replace(['-', '/', '_'], '', $symbol));
    }

    /** Settlements in `[$from, $to]` (inclusive, as Binance has it), oldest first. */
    public function request(string $restUrl, string $symbol, int $from, int $to, int $limit = self::PER_REQUEST): HttpRequest
    {
        $query = http_build_query(['symbol' => $this->symbol($symbol), 'startTime' => $from, 'endTime' => $to, 'limit' => min($limit, self::PER_REQUEST)]);

        return new HttpRequest('GET', rtrim($restUrl, '/') . '/fapi/v1/fundingRate?' . $query);
    }

    /**
     * @return list<Rate> oldest first
     * @throws ExchangeError when the body is not a funding list
     */
    public function parse(string $body): array
    {
        $data = json_decode($body, true);

        if (!is_array($data) || (isset($data['code']) && isset($data['msg']))) {
            throw new ExchangeError('Binance answered an error for funding history: ' . substr($body, 0, 300));
        }

        $out = [];

        foreach ($data as $row) {
            if (!is_array($row) || !isset($row['fundingTime'], $row['fundingRate'])) {
                throw new ExchangeError('Binance answered something other than funding history: ' . substr($body, 0, 300));
            }

            $out[] = new Rate((int) $row['fundingTime'], (float) $row['fundingRate']);
        }

        usort($out, static fn (Rate $a, Rate $b): int => $a->time <=> $b->time);

        return $out;
    }
}
