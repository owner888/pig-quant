<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\Ai\Http\HttpClient;
use Pig\Ai\Http\Request;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Logger;
use Quant\Market\Candle;
use Quant\Market\Side;
use Quant\Wallet\Trade;

/**
 * What `/quant`, the `quant_*` tools and the panel call: one set of operations that run on this
 * process's engine when it owns the engine (or nobody does yet), and are forwarded to the owner's
 * loopback port when another process does (`Owner`).
 *
 * Reads with no owner anywhere (`status`, `trades`, `candles`) answer from this process's engine,
 * which has the saved wallet and no stream. Anything that changes the wallet or starts the stream
 * (`start`, `order`, `reset`) makes this process the owner first, so there is never a second
 * writer of `state.json`.
 *
 * The engine and the listener are the process's, not this object's: `/web` from the terminal
 * runs the web server in the same process and loads the extensions a second time, and `/reload`
 * loads them again — a second `Hub` in the process must find the engine the first one started.
 */
final class Hub
{
    private const float FORWARD_TIMEOUT = 30.0;

    private static ?Runner $runner = null;

    private static ?Owner $owner = null;

    public function __construct(private readonly ExtensionApi $pi)
    {
    }

    /** @return array<string, mixed> */
    public function status(): array
    {
        return $this->call('GET', '/status', [], fn (): array => $this->localStatus());
    }

    /** @return list<array<string, mixed>> */
    public function trades(int $limit): array
    {
        return $this->call('GET', '/trades', ['limit' => $limit], fn (): array => array_map(
            static fn (Trade $t): array => $t->toArray(),
            array_slice($this->runner()->engine->wallet->trades(), -max(1, $limit)),
        ));
    }

    /** @return array<string, mixed> */
    public function candles(string $symbol, int $limit): array
    {
        return $this->call('GET', '/candles', ['symbol' => $symbol, 'limit' => $limit], function () use ($symbol, $limit): array {
            $series = $this->runner()->engine->series($symbol);

            return [
                'symbol' => $series->symbol,
                'interval' => $series->interval,
                'candles' => array_map(static fn (Candle $c): array => $c->toArray(), array_slice($series->all(), -max(1, min(500, $limit)))),
            ];
        });
    }

    /** @return array<string, mixed> the trade */
    public function order(string $side, string $symbol, ?float $quoteAmount, ?float $quantity, string $reason): array
    {
        return $this->call('POST', '/order', ['side' => $side, 'symbol' => $symbol, 'quote_amount' => $quoteAmount, 'quantity' => $quantity, 'reason' => $reason], function () use ($side, $symbol, $quoteAmount, $quantity, $reason): array {
            $this->own();

            return $this->runner()->engine->order(Side::from($side), $symbol, $quoteAmount, $quantity, $reason)->toArray();
        });
    }

    /** @return array<string, mixed> the status afterwards */
    public function start(): array
    {
        return $this->call('POST', '/start', [], function (): array {
            $this->own();
            $this->runner()->start();

            return $this->localStatus();
        });
    }

    /** @return array<string, mixed> the status afterwards */
    public function stop(): array
    {
        return $this->call('POST', '/stop', [], function (): array {
            $this->runner()->stop();

            return $this->localStatus();
        });
    }

    /** @return array<string, mixed> the status afterwards */
    public function reset(): array
    {
        return $this->call('POST', '/reset', [], function (): array {
            $this->own();
            $runner = $this->runner();
            $runner->stop();
            State::forget();
            $runner->engine->wallet->restore(['quoteAsset' => $runner->engine->wallet->quoteAsset]);

            return $this->localStatus();
        });
    }

    // ---- the spread watcher ---------------------------------------------------------------

    private static ?SpreadRunner $spread = null;

    /** @return array<string, mixed> the status afterwards */
    public function spreadStart(): array
    {
        return $this->call('POST', '/spread/start', [], function (): array {
            $this->own();
            $this->spread()->start();

            return $this->spread()->status();
        });
    }

    /** @return array<string, mixed> the status afterwards */
    public function spreadStop(): array
    {
        return $this->call('POST', '/spread/stop', [], function (): array {
            $this->spread()->stop();

            return $this->spread()->status();
        });
    }

    /** @return array<string, mixed> */
    public function spreadStatus(): array
    {
        return $this->call('GET', '/spread/status', [], fn (): array => $this->spread()->status());
    }

    /** The files are on this machine whoever writes them: read here. @return array<string, mixed> */
    public function spreadReport(int $days): array
    {
        return $this->spread()->report(max(1, $days));
    }

    private function spread(): SpreadRunner
    {
        return self::$spread ??= Config::spreadRunner(Config::load($this->pi->getSettings()));
    }

    // ---- the funding watcher --------------------------------------------------------------

    private static ?FundingWatcher $funding = null;

    /** @param \Closure(string): void|null $notify where an alert is said out loud @return array<string, mixed> the status afterwards */
    public function fundingWatchStart(?\Closure $notify = null): array
    {
        return $this->call('POST', '/funding/start', [], function () use ($notify): array {
            $this->own();

            // A start forwarded from another process brings no terminal; keep the one that is set.
            if ($notify !== null) {
                $this->fundingWatcher()->onAlert($notify);
            }

            $this->fundingWatcher()->start();

            return $this->fundingWatcher()->status();
        });
    }

    /** @return array<string, mixed> the status afterwards */
    public function fundingWatchStop(): array
    {
        return $this->call('POST', '/funding/stop', [], function (): array {
            $this->fundingWatcher()->stop();

            return $this->fundingWatcher()->status();
        });
    }

    /** @return array<string, mixed> */
    public function fundingWatchStatus(): array
    {
        return $this->call('GET', '/funding/status', [], fn (): array => $this->fundingWatcher()->status());
    }

    private function fundingWatcher(): FundingWatcher
    {
        return self::$funding ??= Config::fundingWatcher(Config::load($this->pi->getSettings()));
    }

    /** On the way out: stop the streams, save the wallet, give the ownership up. */
    public function shutdown(): void
    {
        self::$spread?->stop();
        self::$funding?->stop();

        if (self::$runner !== null) {
            self::$runner->stop();

            if (!$this->pi->isIncognito()) {
                State::save(self::$runner->engine->wallet);
            }
        }

        self::$owner?->release();
    }

    /** This process's engine, built from the settings on first use. */
    public function runner(): Runner
    {
        if (self::$runner !== null) {
            return self::$runner;
        }

        $config = Config::load($this->pi->getSettings());
        $engine = Config::engine($config);

        if (!$this->pi->isIncognito() && State::restore($engine->wallet)) {
            Logger::info('[quant] wallet restored from ' . State::path());
        }

        $engine->onEvent(function (string $event, array $data) use ($engine): void {
            match ($event) {
                'trade' => Logger::info('[quant] ' . Panel::tradeLine($data['trade'])),
                'refused' => Logger::warning("[quant] {$data['symbol']}: buy refused, {$data['reason']}"),
                'signal' => Logger::debug("[quant] {$data['symbol']}: {$data['side']} signal"),
                default => null,
            };

            if ($event === 'trade' && !$this->pi->isIncognito()) {
                State::save($engine->wallet);
            }
        });

        return self::$runner = new Runner($engine, (int) $config['warmupCandles']);
    }

    /** Become the owner: listen, claim the file. A no-op when this process already is. */
    private function own(): void
    {
        self::$owner ??= new Owner(fn (string $method, string $path, array $body): mixed => $this->serve($method, $path, $body));

        if (!self::$owner->isListening()) {
            self::$owner->listen();
        }
    }

    /**
     * Run `$local` here, unless another live process owns the engine — then send the same
     * operation there and answer with what it said.
     *
     * @param array<string, mixed> $params
     * @param \Closure(): mixed $local
     */
    private function call(string $method, string $path, array $params, \Closure $local): mixed
    {
        $other = self::$owner?->isListening() === true ? null : Owner::other();

        if ($other === null) {
            return $local();
        }

        $url = "http://127.0.0.1:{$other['port']}{$path}";
        $body = null;

        if ($method === 'GET') {
            $query = http_build_query(array_filter($params, static fn ($v) => $v !== null));
            $url .= $query === '' ? '' : "?{$query}";
        } else {
            $body = json_encode($params);
        }

        $response = (new HttpClient(timeout: self::FORWARD_TIMEOUT))->send(new Request($method, $url, ['x-quant-token' => $other['token'], 'content-type' => 'application/json'], $body));
        $answer = json_decode($response->body->all(), true);

        if (!is_array($answer) || !array_key_exists('ok', $answer)) {
            throw new \RuntimeException("The engine's process (pid {$other['pid']}) answered {$response->status} with something that is not its JSON.");
        }

        if ($answer['ok'] !== true) {
            throw new \RuntimeException((string) ($answer['error'] ?? 'unknown error'));
        }

        return $answer['result'];
    }

    /** The owner's side of `call()`: the same operations, from a request. @param array<string, mixed> $body */
    private function serve(string $method, string $path, array $body): mixed
    {
        $query = [];

        if (($at = strpos($path, '?')) !== false) {
            parse_str(substr($path, $at + 1), $query);
            $path = substr($path, 0, $at);
        }

        $params = $body + $query;

        return match ("{$method} {$path}") {
            'GET /status' => $this->localStatus(),
            'GET /trades' => $this->trades((int) ($params['limit'] ?? 50)),
            'GET /candles' => $this->candles((string) ($params['symbol'] ?? ''), (int) ($params['limit'] ?? 50)),
            'POST /order' => $this->order(
                (string) ($params['side'] ?? ''),
                (string) ($params['symbol'] ?? ''),
                isset($params['quote_amount']) ? (float) $params['quote_amount'] : null,
                isset($params['quantity']) ? (float) $params['quantity'] : null,
                (string) ($params['reason'] ?? 'manual'),
            ),
            'POST /start' => $this->start(),
            'POST /stop' => $this->stop(),
            'POST /reset' => $this->reset(),
            'POST /spread/start' => $this->spreadStart(),
            'POST /spread/stop' => $this->spreadStop(),
            'GET /spread/status' => $this->spreadStatus(),
            'POST /funding/start' => $this->fundingWatchStart(),
            'POST /funding/stop' => $this->fundingWatchStop(),
            'GET /funding/status' => $this->fundingWatchStatus(),
            default => throw new \RuntimeException("No such operation: {$method} {$path}"),
        };
    }

    /** @return array<string, mixed> */
    private function localStatus(): array
    {
        $runner = $this->runner();

        return [
            'running' => $runner->isRunning(),
            'problem' => $runner->problem(),
            'reconnects' => $runner->reconnects(),
            'retrying' => $runner->retrying(),
            'connectedAt' => $runner->connectedAt(),
            'ownerPid' => self::$owner?->isListening() === true ? getmypid() : null,
            'answeredBy' => getmypid(),
            'lastTrades' => array_map(static fn (Trade $t): array => $t->toArray(), array_slice($runner->engine->wallet->trades(), -20)),
        ] + $runner->engine->status();
    }
}
