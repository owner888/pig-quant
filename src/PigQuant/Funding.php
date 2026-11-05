<?php

declare(strict_types=1);

namespace PigQuant;

use Closure;
use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\Ai\Http\Retrying;
use Pig\CodingAgent\Config as PigConfig;
use Pig\CodingAgent\Settings;
use Quant\Exchange\ExchangeError;
use Quant\Funding\BinanceFunding;
use Quant\Funding\Carry;
use Quant\Funding\Rate;

/**
 * `/quant funding <symbol> <from> [to]` and `quant_funding`: what a cash-and-carry on the
 * perpetual would have collected in funding over a range, from Binance's funding history
 * (`quant.funding.restUrl`), each whole month kept under `~/.pig/agent/quant/funding/binance/`
 * like candles are. Observation only — nothing is traded, no account is touched.
 */
final class Funding
{
    private const float HTTP_TIMEOUT = 30.0;

    private const int PAGE_ATTEMPTS = 3;

    private const float RETRY_WAIT = 2.0;

    public function __construct(
        private readonly string $restUrl,
        private readonly BinanceFunding $source = new BinanceFunding(),
        private readonly HttpClient $http = new HttpClient(timeout: self::HTTP_TIMEOUT),
    ) {
    }

    public static function directory(): string
    {
        return PigConfig::home() . '/quant/funding';
    }

    /**
     * The summary for a range, with the costs the settings name.
     *
     * @param Closure(string): void|null $progress a line at a time
     * @return array{symbol: string, summary: array<string, mixed>}
     */
    public static function run(?Settings $settings, string $symbol, int $from, int $to, ?Closure $progress = null): array
    {
        if ($to <= $from) {
            throw new \InvalidArgumentException('The range ends before it starts.');
        }

        $config = Config::load($settings);
        $funding = new self((string) $config['funding']['restUrl']);
        $symbol = $funding->source->symbol($symbol);
        $rates = $funding->range($symbol, $from, $to, $progress);
        $summary = Carry::summarize($rates, (float) $config['feeRate'], (float) $config['funding']['perpFeeRate'], (float) $config['slippage'], (float) $config['funding']['marginRatio']);

        return ['symbol' => $symbol, 'summary' => $summary];
    }

    /**
     * `<symbol> <from> [to]`; dates as a backtest's.
     *
     * @return array{symbol: string, from: int, to: int}
     */
    public static function parseArgs(string $args): array
    {
        $parts = preg_split('/\s+/', trim($args), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) < 2) {
            throw new \InvalidArgumentException('Usage: /quant funding <symbol> <from> [to]  — e.g. /quant funding BTC-USDT 2024-11-01 2026-11-01');
        }

        return [
            'symbol' => $parts[0],
            'from' => Backtest::parseDate($parts[1]),
            'to' => isset($parts[2]) ? Backtest::parseDate($parts[2]) : (int) floor(microtime(true) * 1000),
        ];
    }

    /**
     * Settlements in `[$from, $to)`, oldest first: whole months from the cache or fetched and
     * kept, the rest fetched for the part asked.
     *
     * @param Closure(string): void|null $progress
     * @return list<Rate>
     */
    public function range(string $symbol, int $from, int $to, ?Closure $progress = null): array
    {
        $out = [];

        foreach (History::months($from, $to) as [$start, $end, $month]) {
            $whole = $start >= $from && $end <= $to;
            $file = sprintf('%s/%s/%s-%s.json', self::directory(), $this->source->name(), $symbol, $month);

            if ($whole && is_file($file)) {
                $rates = self::read($file);
            } else {
                if ($progress !== null) {
                    $progress("{$symbol} funding {$month}: fetching…");
                }

                $rates = $this->fetch($symbol, max($start, $from), min($end, $to), $progress);

                if ($whole) {
                    self::write($file, $rates);
                }
            }

            foreach ($rates as $rate) {
                if ($rate->time >= $from && $rate->time < $to) {
                    $out[] = $rate;
                }
            }
        }

        return $out;
    }

    /**
     * @param Closure(string): void|null $progress
     * @return list<Rate>
     */
    private function fetch(string $symbol, int $from, int $to, ?Closure $progress): array
    {
        $out = [];
        $cursor = $from;

        while ($cursor < $to) {
            $request = $this->source->request($this->restUrl, $symbol, $cursor, $to - 1);
            [$response, $body] = Retrying::fetch(
                $this->http,
                new Request($request->method, $request->url, $request->headers, $request->body),
                attempts: self::PAGE_ATTEMPTS,
                baseDelay: self::RETRY_WAIT,
                onRetry: static function (int $attempt, string $reason, float $wait) use ($symbol, $progress): void {
                    if ($progress !== null) {
                        $progress(sprintf('%s funding: %s — trying again in %.0fs (%d of %d)', $symbol, $reason, $wait, $attempt + 1, self::PAGE_ATTEMPTS));
                    }
                },
            );

            if (!$response->isSuccessful()) {
                throw new ExchangeError("{$this->source->name()} answered {$response->status} {$response->reason} for {$symbol} funding: " . substr($body, 0, 300));
            }

            $page = $this->source->parse($body);

            if ($page === []) {
                break;
            }

            foreach ($page as $rate) {
                $out[] = $rate;
            }

            $cursor = $page[count($page) - 1]->time + 1;

            if (count($page) < BinanceFunding::PER_REQUEST) {
                break;
            }
        }

        return $out;
    }

    /** @return list<Rate> */
    private static function read(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);

        if (!is_array($data)) {
            throw new \RuntimeException("{$file} is not a funding file; delete it to fetch the month again.");
        }

        return array_map(static fn (array $r): Rate => new Rate((int) $r[0], (float) $r[1]), $data);
    }

    /** @param list<Rate> $rates */
    private static function write(string $file, array $rates): void
    {
        $directory = dirname($file);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        $rows = array_map(static fn (Rate $r): array => [$r->time, $r->rate], $rates);

        if (file_put_contents($file, json_encode($rows, JSON_PRESERVE_ZERO_FRACTION) . "\n") === false) {
            throw new \RuntimeException("Could not write {$file}.");
        }
    }
}
