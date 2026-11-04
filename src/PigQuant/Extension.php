<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\Agent\AgentToolResult;
use Pig\Ai\TextContent;
use Pig\CodingAgent\CustomTools\CustomTool;
use Pig\CodingAgent\Extensions\ExtensionApi;
use Pig\CodingAgent\Hooks\HookContext;
use Pig\CodingAgent\Logger;
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

            if (Config::load($pi->getSettings())['autostart'] === true) {
                try {
                    $hub->start();
                } catch (Throwable $error) {
                    Logger::error('[quant] autostart failed: ' . $error->getMessage());
                    $ctx->ui->notify('quant: ' . $error->getMessage(), 'error');
                }
            }
        });

        $pi->on('session_shutdown', static fn () => $hub->shutdown());

        // -------------------------------------------------------------------------
        // Slash command: /quant
        // -------------------------------------------------------------------------
        $pi->registerCommand(
            name: 'quant',
            description: 'Dry-run trading: /quant status | start | stop | trades | buy <symbol> [quote] | sell <symbol> | reset',
            handler: static function (string $args, HookContext $ctx) use ($hub): void {
                $parts = preg_split('/\s+/', trim($args), 3, PREG_SPLIT_NO_EMPTY) ?: [];
                $sub = strtolower($parts[0] ?? 'status');

                try {
                    switch ($sub) {
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
                            $ctx->ui->notify("No '/quant {$sub}'. There is: status, start, stop, trades, buy, sell, reset.", 'error');
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
