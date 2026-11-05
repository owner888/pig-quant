<?php

declare(strict_types=1);

namespace PigQuant;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Retrying;
use Pig\CodingAgent\Config as PigConfig;
use Quant\Exchange\Exchange;
use Quant\Exchange\ExchangeError;
use Quant\Market\Candle;
use Quant\Transport\HttpRequest;

/**
 * Candles for a time range, from the exchange's REST endpoint a page at a time, with each whole
 * calendar month kept in `~/.pig/agent/quant/candles/<exchange>/<symbol>-<interval>-<YYYY-MM>.json`
 * so a second backtest over the same months downloads nothing. A month the range only touches —
 * the warm-up before it, the month in progress — is fetched for the part asked and not kept: a
 * 200-candle warm-up is not a reason to download a month.
 *
 * A page the network loses, or a 429/5xx, is asked for again through pig's `Retrying` — three
 * attempts, a growing wait — before the fetch gives up; what the exchange refuses (a 4xx) is not.
 */
final class History
{
    private const float HTTP_TIMEOUT = 30.0;

    private const int PAGE_ATTEMPTS = 3;

    private const float RETRY_WAIT = 2.0;

    public function __construct(
        private readonly Exchange $exchange,
        private readonly HttpClient $http = new HttpClient(timeout: self::HTTP_TIMEOUT),
    ) {
    }

    public static function directory(): string
    {
        return PigConfig::home() . '/quant/candles';
    }

    /**
     * The candles whose open time is in `[$from, $to)`, oldest first.
     *
     * @param Closure(string): void|null $progress told what is being fetched, a line at a time
     * @return list<Candle>
     */
    public function range(string $symbol, string $interval, int $from, int $to, ?Closure $progress = null): array
    {
        $symbol = $this->exchange->symbol($symbol);
        $candles = [];

        foreach (self::months($from, $to) as [$monthStart, $monthEnd, $key]) {
            $file = self::directory() . '/' . $this->exchange->name() . "/{$symbol}-{$interval}-{$key}.json";
            // Kept only when the range covers the whole month and the month is over.
            $whole = $from <= $monthStart && $to >= $monthEnd && $monthEnd <= (int) floor(microtime(true) * 1000);

            if (is_file($file)) {
                $month = self::readFile($file);
            } else {
                $start = max($from, $monthStart);
                $end = min($to, $monthEnd);

                if ($progress !== null) {
                    $progress("{$symbol} {$interval} {$key}: fetching" . ($whole ? '…' : ' ' . gmdate('m-d H:i', intdiv($start, 1000)) . ' → ' . gmdate('m-d H:i', intdiv($end, 1000)) . '…'));
                }

                $month = $this->fetch($symbol, $interval, $start, $end, $progress);

                if ($whole) {
                    self::writeFile($file, $month);
                }
            }

            foreach ($month as $candle) {
                if ($candle->openTime >= $from && $candle->openTime < $to) {
                    $candles[] = $candle;
                }
            }
        }

        return $candles;
    }

    /**
     * Every candle with an open time in `[$from, $to)`, straight from the exchange, page by page.
     *
     * @param Closure(string): void|null $progress
     * @return list<Candle>
     */
    public function fetch(string $symbol, string $interval, int $from, int $to, ?Closure $progress = null): array
    {
        $candles = [];
        $cursor = $from;
        $pageSize = $this->exchange->candlesPerRequest();
        $pages = 0;

        while ($cursor < $to) {
            $request = $this->exchange->candlesRequest($symbol, $interval, $pageSize, $cursor, $to - 1);
            $page = $this->page($request, $symbol, $progress);
            $pages++;

            if ($page === []) {
                break;
            }

            foreach ($page as $candle) {
                if ($candle->openTime >= $from && $candle->openTime < $to) {
                    $candles[] = $candle;
                }
            }

            $last = $page[count($page) - 1];

            if (count($page) < $pageSize || $last->openTime + 1 >= $to) {
                break;
            }

            $cursor = $last->openTime + 1;
            if ($progress !== null) {
                $progress(sprintf('%s %s: %d candles so far (page %d, up to %s)', $symbol, $interval, count($candles), $pages, gmdate('Y-m-d H:i', intdiv($last->openTime, 1000))));
            }
        }

        return $candles;
    }

    /**
     * One page — pig's `Retrying::fetch()` asks again when the network or a busy server loses it.
     *
     * @param Closure(string): void|null $progress
     * @return list<Candle>
     */
    private function page(HttpRequest $request, string $symbol, ?Closure $progress): array
    {
        [$response, $body] = Retrying::fetch(
            $this->http,
            new Request($request->method, $request->url, $request->headers, $request->body),
            attempts: self::PAGE_ATTEMPTS,
            baseDelay: self::RETRY_WAIT,
            onRetry: static function (int $attempt, string $reason, float $wait) use ($symbol, $progress): void {
                if ($progress !== null) {
                    $progress(sprintf('%s: %s — trying again in %.0fs (%d of %d)', $symbol, $reason, $wait, $attempt + 1, self::PAGE_ATTEMPTS));
                }
            },
        );

        if (!$response->isSuccessful()) {
            throw new ExchangeError("{$this->exchange->name()} answered {$response->status} {$response->reason} for {$symbol} candles: " . substr($body, 0, 300));
        }

        return $this->exchange->parseCandles($body);
    }

    /**
     * The calendar months (UTC) that cover `[$from, $to)`: `[start, end, "YYYY-MM"]` each.
     *
     * @return list<array{0: int, 1: int, 2: string}>
     */
    public static function months(int $from, int $to): array
    {
        $months = [];
        $year = (int) gmdate('Y', intdiv($from, 1000));
        $month = (int) gmdate('n', intdiv($from, 1000));

        while (true) {
            $start = gmmktime(0, 0, 0, $month, 1, $year) * 1000;
            $end = gmmktime(0, 0, 0, $month + 1, 1, $year) * 1000;

            if ($start >= $to) {
                break;
            }

            $months[] = [$start, $end, sprintf('%04d-%02d', $year, $month)];
            $month++;

            if ($month > 12) {
                $month = 1;
                $year++;
            }
        }

        return $months;
    }

    /** @return list<Candle> */
    private static function readFile(string $file): array
    {
        $rows = json_decode((string) file_get_contents($file), true);

        if (!is_array($rows)) {
            throw new \RuntimeException("{$file} is not the candle cache this package writes; remove it to fetch again.");
        }

        return array_map(static fn (array $r): Candle => new Candle((int) $r[0], (float) $r[1], (float) $r[2], (float) $r[3], (float) $r[4], (float) $r[5], (int) $r[6], true), $rows);
    }

    /** @param list<Candle> $candles */
    private static function writeFile(string $file, array $candles): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        $rows = array_map(static fn (Candle $c): array => [$c->openTime, $c->open, $c->high, $c->low, $c->close, $c->volume, $c->closeTime], $candles);

        if (file_put_contents($file, json_encode($rows, JSON_PRESERVE_ZERO_FRACTION)) === false) {
            throw new \RuntimeException("Could not write {$file}.");
        }
    }
}
