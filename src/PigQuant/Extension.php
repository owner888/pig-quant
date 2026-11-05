<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Logger;
use Quant\Backtest\Sweep as Grid;
use Quant\Wallet\Trade;
use Throwable;

/**
 * pig-quant in pig: the `/quant` command, the `quant_*` tools and the web UI panel, all over one
 * `Hub` — which runs the engine here or forwards to the process that does (`Owner`).
 */
final class Extension
{
    private static function json(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    public static function register(ExtensionApi $pi): void
    {
        $hub = new Hub($pi);

        $pi->on('session_start', static function ($event, HookContext $ctx) use ($pi, $hub): void {
            Config::writeDefaults($pi->getSettings());

            $config = Config::load($pi->getSettings());

            if ($config['autostart'] === true) {
                try {
                    $hub->start();
                } catch (Throwable $error) {
                    Logger::error('[quant] autostart failed: ' . $error->getMessage());
                    $ctx->ui->notify('quant: ' . $error->getMessage(), 'error');
                }
            }

            // For a machine left to watch: `quant.spread.autostart` starts the watcher with the session.
            if ($config['spread']['autostart'] === true) {
                try {
                    $hub->spreadStart();
                } catch (Throwable $error) {
                    Logger::error('[quant spread] autostart failed: ' . $error->getMessage());
                    $ctx->ui->notify('quant spread: ' . $error->getMessage(), 'error');
                }
            }
        });

        $pi->on('session_shutdown', static fn () => $hub->shutdown());

        // -------------------------------------------------------------------------
        // Slash command: /quant
        // -------------------------------------------------------------------------
        $pi->registerCommand(
            name: 'quant',
            description: 'Dry-run trading: /quant status | start | stop | trades | buy <symbol> [quote] | sell <symbol> | reset | backtest <symbol[,symbol…]> <interval> <from> [to] [fast=9 slow=21 …] | sweep <symbol[,…]> <interval> <from> [to] fast=10..30:5 slow=30..100:10 | spread start|stop|status|report [days]',
            handler: static function (string $args, HookContext $ctx) use ($hub, $pi): void {
                $parts = preg_split('/\s+/', trim($args), 3, PREG_SPLIT_NO_EMPTY) ?: [];
                $sub = strtolower($parts[0] ?? 'status');

                try {
                    switch ($sub) {
                        case 'spread':
                            $what = strtolower($parts[1] ?? 'status');
                            match ($what) {
                                'start' => $ctx->ui->notify(self::spreadText($hub->spreadStart(), '▶ ')),
                                'stop' => (static function () use ($hub, $ctx): void {
                                    $hub->spreadStop();
                                    $ctx->ui->notify('⏹ quant spread: stopped');
                                })(),
                                'status' => $ctx->ui->notify(self::spreadText($hub->spreadStatus(), '')),
                                'report' => $ctx->ui->notify("📊 Spread\n  " . implode("\n  ", SpreadRunner::lines($hub->spreadReport((int) ($parts[2] ?? 1))))),
                                default => $ctx->ui->notify("No '/quant spread {$what}'. There is: start, stop, status, report [days].", 'error'),
                            };

                            return;

                        case 'backtest':
                            $spec = Backtest::parseArgs(substr(trim($args), strlen($parts[0])));
                            $run = Backtest::run($pi->getSettings(), $spec['symbol'], $spec['interval'], $spec['from'], $spec['to'], $spec['overrides'], static fn (string $line) => $ctx->ui->notify('quant: ' . $line));
                            $ctx->ui->notify("📊 Backtest\n  " . implode("\n  ", $run['report']->lines()) . "\n  Saved to {$run['file']}");

                            return;

                        case 'sweep':
                            $spec = Backtest::parseArgs(substr(trim($args), strlen($parts[0])), 'sweep');
                            $sweep = Sweep::run($pi->getSettings(), $spec['symbol'], $spec['interval'], $spec['from'], $spec['to'], $spec['overrides'], static fn (string $line) => $ctx->ui->notify('quant: ' . $line));
                            $ctx->ui->notify("📊 Sweep\n  " . implode("\n  ", Grid::lines($sweep['results'], $sweep['sweeps'])) . ($sweep['skipped'] === [] ? '' : "\n  Skipped " . count($sweep['skipped']) . ': ' . implode('; ', array_unique($sweep['skipped']))) . "\n  Saved to {$sweep['file']}");

                            return;

                        case 'start':
                            $status = $hub->start();
                            $ctx->ui->notify('✅ quant: streaming ' . implode(', ', $status['symbols']) . " {$status['interval']} on {$status['exchange']} (dry run, pid {$status['ownerPid']})");

                            return;

                        case 'stop':
                            $hub->stop();
                            $ctx->ui->notify('⏹ quant: stopped');

                            return;

                        case 'trades':
                            $trades = $hub->trades((int) ($parts[1] ?? 20));
                            $ctx->ui->notify($trades === [] ? 'No trades yet.' : implode("\n", array_map(
                                static fn (array $t): string => date('m-d H:i:s', intdiv((int) $t['time'], 1000)) . '  ' . Panel::tradeLine(Trade::fromArray($t)),
                                $trades,
                            )));

                            return;

                        case 'buy':
                        case 'sell':
                            $symbol = $parts[1] ?? throw new \RuntimeException("Usage: /quant {$sub} <symbol>" . ($sub === 'buy' ? ' [quote amount]' : ''));
                            $amount = isset($parts[2]) ? (float) $parts[2] : null;
                            $trade = $hub->order($sub, $symbol, $sub === 'buy' ? $amount : null, $sub === 'sell' ? $amount : null, 'manual');
                            $ctx->ui->notify('✅ ' . Panel::tradeLine(Trade::fromArray($trade)));

                            return;

                        case 'reset':
                            $status = $hub->status();

                            if (!$ctx->ui->confirm('Reset the paper wallet?', 'Every trade is forgotten and the balance goes back to ' . Panel::money($status['initialQuote']) . ' ' . $status['quoteAsset'] . '.')) {
                                return;
                            }

                            $hub->reset();
                            $ctx->ui->notify('✅ quant: wallet reset');

                            return;

                        case 'status':
                        case '':
                            $ctx->ui->notify(self::statusText($hub->status()));

                            return;

                        default:
                            $ctx->ui->notify("No '/quant {$sub}'. There is: status, start, stop, trades, buy, sell, reset, backtest, sweep, spread.", 'error');
                    }
                } catch (Throwable $error) {
                    $ctx->ui->notify('❌ quant: ' . $error->getMessage(), 'error');
                }
            },
        );

        // -------------------------------------------------------------------------
        // Tools
        // -------------------------------------------------------------------------
        $pi->registerTool(new CustomTool(
            name: 'quant_status',
            label: 'Quant status',
            description: 'The dry-run trading engine: whether it is streaming (and in which pig process, ownerPid), the exchange, symbols, strategy and risk limits, cash, equity, open positions with unrealized P&L, last prices, the last trades.',
            parameters: ['type' => 'object', 'properties' => []],
            execute: static fn (string $id, array $params): AgentToolResult => new AgentToolResult([new TextContent(self::json($hub->status()))]),
        ));

        $pi->registerTool(new CustomTool(
            name: 'quant_candles',
            label: 'Quant candles',
            description: 'The last candles the engine holds for a symbol it trades (no network call): openTime, open, high, low, close, volume, closeTime, closed. Times are Unix milliseconds.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'symbol' => ['type' => 'string', 'description' => 'A symbol the engine trades, e.g. BTCUSDT'],
                    'limit' => ['type' => 'integer', 'description' => 'How many, newest last (default 50, max 500)'],
                ],
                'required' => ['symbol'],
            ],
            execute: static fn (string $id, array $params): AgentToolResult => new AgentToolResult([new TextContent(self::json(
                $hub->candles((string) $params['symbol'], (int) ($params['limit'] ?? 50)),
            ))]),
        ));

        $pi->registerTool(new CustomTool(
            name: 'quant_trades',
            label: 'Quant trades',
            description: 'The paper trades so far, oldest first: time, symbol, side, quantity, price, fee, quoteAmount, pnl (sells only), reason.',
            parameters: [
                'type' => 'object',
                'properties' => ['limit' => ['type' => 'integer', 'description' => 'The last N trades (default 50)']],
            ],
            execute: static fn (string $id, array $params): AgentToolResult => new AgentToolResult([new TextContent(self::json(
                $hub->trades((int) ($params['limit'] ?? 50)),
            ))]),
        ));

        $pi->registerTool(new CustomTool(
            name: 'quant_order',
            label: 'Quant order (dry run)',
            description: 'Place a paper order at the last price seen, through the same risk limits the strategy has. Nothing reaches an exchange. A buy spends quote_amount (the configured order size when omitted); a sell closes quantity of the position (all of it when omitted). Refused orders throw with the reason.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'side' => ['type' => 'string', 'enum' => ['buy', 'sell']],
                    'symbol' => ['type' => 'string', 'description' => 'A symbol the engine trades'],
                    'quote_amount' => ['type' => 'number', 'description' => 'Buy: how much quote (USDT) to spend'],
                    'quantity' => ['type' => 'number', 'description' => 'Sell: how much base to sell'],
                    'reason' => ['type' => 'string', 'description' => 'Why, in a few words; kept with the trade'],
                ],
                'required' => ['side', 'symbol'],
            ],
            execute: static fn (string $id, array $params): AgentToolResult => new AgentToolResult([new TextContent(self::json($hub->order(
                (string) $params['side'],
                (string) $params['symbol'],
                isset($params['quote_amount']) ? (float) $params['quote_amount'] : null,
                isset($params['quantity']) ? (float) $params['quantity'] : null,
                (string) ($params['reason'] ?? 'model'),
            )))]),
        ));

        $pi->registerTool(new CustomTool(
            name: 'quant_backtest',
            label: 'Quant backtest',
            description: 'Run the configured strategy over past candles (fetched from the exchange, cached by month) and report: final equity, return vs buy-and-hold, max drawdown, trades, win rate, profit factor, fees, average hold, the last trades and a downsampled equity curve. The dry-run wallet is not touched. Overrides change one run only. Takes seconds to a minute for a month of 1m candles.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'symbol' => ['type' => 'string', 'description' => 'e.g. BTCUSDT; a basket is comma-separated, BTC-USDT,ETH-USDT,SOL-USDT — one wallet, equal-weight buy & hold for comparison'],
                    'interval' => ['type' => 'string', 'description' => 'Candle interval: 1m, 5m, 15m, 1h, 4h, 1d'],
                    'from' => ['type' => 'string', 'description' => 'Start, UTC: 2026-10-01 or 2026-10-01T09:30'],
                    'to' => ['type' => 'string', 'description' => 'End, UTC; now when omitted'],
                    'overrides' => ['type' => 'object', 'description' => 'Settings for this run only, by name: ' . implode(', ', Backtest::OVERRIDES) . '. orderQuote, maxOrderQuote and maxExposureQuote may also be "all" or "50%" (a share of the free cash for the order size, of the equity for the limits) so profits compound.', 'additionalProperties' => ['type' => ['number', 'string']]],
                ],
                'required' => ['symbol', 'interval', 'from'],
            ],
            execute: static function (string $id, array $params) use ($pi): AgentToolResult {
                $run = Backtest::run(
                    $pi->getSettings(),
                    (string) $params['symbol'],
                    (string) $params['interval'],
                    Backtest::parseDate((string) $params['from']),
                    isset($params['to']) ? Backtest::parseDate((string) $params['to']) : (int) floor(microtime(true) * 1000),
                    is_array($params['overrides'] ?? null) ? $params['overrides'] : [],
                );
                $report = $run['report']->toArray();
                // The curve and every trade would be most of the tokens; the model gets the figures and the last 20 trades.
                $report['curve'] = count($run['report']->curve);
                $report['tradeList'] = array_slice($report['tradeList'], -20);
                $report['file'] = $run['file'];

                return new AgentToolResult([new TextContent(self::json($report))]);
            },
        ));

        $pi->registerTool(new CustomTool(
            name: 'quant_sweep',
            label: 'Quant sweep',
            description: 'Backtest every combination of parameter ranges over the same candles and return one row per combination (return, buy-and-hold, max drawdown, profit factor, closed trades, win rate). Use it to see whether a good parameter sits among good neighbours or alone; a lone good cell is an overfit. A range is "10..30:5" (start..end:step), a list "30,50,100"; a plain number holds that setting for every run. At most 500 combinations.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'symbol' => ['type' => 'string', 'description' => 'e.g. BTCUSDT; a basket is comma-separated, BTC-USDT,ETH-USDT,SOL-USDT — one wallet, equal-weight buy & hold for comparison'],
                    'interval' => ['type' => 'string', 'description' => 'Candle interval: 1m, 5m, 15m, 1h, 4h, 1d'],
                    'from' => ['type' => 'string', 'description' => 'Start, UTC: 2026-10-01 or 2026-10-01T09:30'],
                    'to' => ['type' => 'string', 'description' => 'End, UTC; now when omitted'],
                    'overrides' => ['type' => 'object', 'description' => 'By name (' . implode(', ', Backtest::OVERRIDES) . '): a range "10..30:5", a list "30,50,100", or one number; orderQuote, maxOrderQuote and maxExposureQuote may be "all" or "50%"', 'additionalProperties' => ['type' => 'string']],
                ],
                'required' => ['symbol', 'interval', 'from', 'overrides'],
            ],
            execute: static function (string $id, array $params) use ($pi): AgentToolResult {
                $sweep = Sweep::run(
                    $pi->getSettings(),
                    (string) $params['symbol'],
                    (string) $params['interval'],
                    Backtest::parseDate((string) $params['from']),
                    isset($params['to']) ? Backtest::parseDate((string) $params['to']) : (int) floor(microtime(true) * 1000),
                    array_map(static fn ($v): string => (string) $v, is_array($params['overrides'] ?? null) ? $params['overrides'] : []),
                );
                $first = $sweep['results'][0]['report'];

                return new AgentToolResult([new TextContent(self::json([
                    'symbol' => $first->symbol,
                    'interval' => $first->interval,
                    'strategy' => $first->strategy,
                    'candles' => $first->candles,
                    'buyAndHoldPct' => $first->buyAndHoldPct,
                    'buyAndHoldDrawdownPct' => $first->buyAndHoldDrawdownPct,
                    'sweeps' => $sweep['sweeps'],
                    'skipped' => $sweep['skipped'],
                    'runs' => Sweep::rows($sweep['results']),
                    'table' => Grid::lines($sweep['results'], $sweep['sweeps']),
                    'file' => $sweep['file'],
                ]))]);
            },
        ));

        $pi->registerTool(new CustomTool(
            name: 'quant_spread',
            label: 'Quant spread',
            description: 'The price gap between two exchanges for one symbol, watched live (observation only, nothing is traded). "status": whether it is running, the live gap in bps and the latest prices. "report": the complete minutes of the last N days summarized — mean and percentile gaps, how often and how long the gap cleared the round-trip cost threshold. 1 bps = 0.01%.',
            parameters: [
                'type' => 'object',
                'properties' => [
                    'what' => ['type' => 'string', 'enum' => ['status', 'report']],
                    'days' => ['type' => 'integer', 'description' => 'For report: the last N days, today included (default 1)'],
                ],
                'required' => ['what'],
            ],
            execute: static fn (string $id, array $params): AgentToolResult => new AgentToolResult([new TextContent(self::json(
                ($params['what'] ?? 'status') === 'report' ? $hub->spreadReport((int) ($params['days'] ?? 1)) : $hub->spreadStatus(),
            ))]),
        ));

        // -------------------------------------------------------------------------
        // Web UI panel
        // -------------------------------------------------------------------------
        $pi->registerWebPanel(
            Panel::ID,
            Panel::TITLE,
            static fn (array $request): array => Panel::blocks($hub->status(), $request['locale']),
            static function (string $action, array $args, array $request) use ($hub): ?string {
                try {
                    match ($action) {
                        'start' => $hub->start(),
                        'stop' => $hub->stop(),
                        'close' => $hub->order('sell', (string) ($args['symbol'] ?? ''), null, null, 'manual'),
                        'reset' => $hub->reset(),
                        default => throw new \RuntimeException("No action '{$action}'."),
                    };
                } catch (Throwable $error) {
                    return $error->getMessage();
                }

                return null;
            },
            icon: '📈',
            commands: ['quant'],
        );
    }

    /** @param array<string, mixed> $status `SpreadRunner::status()` */
    private static function spreadText(array $status, string $prefix): string
    {
        [$a, $b] = $status['exchanges'];
        $head = $status['running']
            ? sprintf('%squant spread: watching %s on %s vs %s (%s)', $prefix, $status['symbol'], $a, $b, $status['interval'])
            : ($status['problem'] !== null ? "⛔ quant spread: stopped on an error — {$status['problem']}" : '⏸ quant spread: not started (/quant spread start)');
        $lines = [$head];

        if ($status['liveBps'] !== null) {
            $lines[] = sprintf('  Now: %s %.2f vs %s %.2f → %+.2f bps (threshold %.0f) · %d minutes written this session', $a, $status['latest']['a'], $b, $status['latest']['b'], $status['liveBps'], $status['thresholdBps'], $status['minutesThisSession']);
        }

        foreach ($status['streams'] as $stream) {
            if ($stream['retrying'] !== null) {
                $lines[] = sprintf('  %s: cannot reach the stream (attempt %d): %s', $stream['exchange'], $stream['retrying']['attempt'], $stream['retrying']['error']);
            } elseif ($stream['connectedAt'] !== null) {
                $lines[] = sprintf('  %s: since %s, %d reconnect(s)', $stream['exchange'], date('H:i:s', $stream['connectedAt']), $stream['reconnects']);
            }
        }

        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $status */
    private static function statusText(array $status): string
    {
        $quote = $status['quoteAsset'];
        $pnl = $status['equity'] - $status['initialQuote'];
        $lines = [];
        $lines[] = $status['running'] && $status['retrying'] !== null
            ? sprintf('⚠️ quant: cannot reach the stream (attempt %d): %s — retrying in %ds', $status['retrying']['attempt'], $status['retrying']['error'], (int) $status['retrying']['nextIn'])
            : ($status['running']
            ? sprintf('📈 quant: running in pid %s — %s %s on %s, %s %s', $status['ownerPid'], implode(' ', $status['symbols']), $status['interval'], $status['exchange'], $status['strategy'], json_encode($status['parameters']))
            : ($status['problem'] !== null ? "⛔ quant: stopped on an error — {$status['problem']}" : '⏸ quant: not started (/quant start)'));
        $lines[] = sprintf('  Equity %s %s (%s%s, %s%.2f%%) · cash %s · realized %s · %d trades',
            Panel::money($status['equity']), $quote, $pnl >= 0 ? '+' : '', Panel::money($pnl), $pnl >= 0 ? '+' : '', $pnl / $status['initialQuote'] * 100,
            Panel::money($status['quote']), Panel::money($status['realizedPnl']), $status['trades']);

        foreach ($status['positions'] as $p) {
            $lines[] = sprintf('  %s %s @ %s → %s (%s%s)', $p['symbol'], Panel::number($p['quantity']), Panel::money($p['averagePrice']), Panel::money($p['price']), $p['unrealizedPnl'] >= 0 ? '+' : '', Panel::money($p['unrealizedPnl']));
        }

        if ($status['connectedAt'] !== null) {
            $lines[] = sprintf('  Stream since %s, %d reconnect(s), %d candles this session', date('H:i:s', $status['connectedAt']), $status['reconnects'], $status['candlesSeen']);
        }

        return implode("\n", $lines);
    }
}
