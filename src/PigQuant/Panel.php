<?php

declare(strict_types=1);

namespace PigQuant;

use Quant\Wallet\Trade;

/** The web UI panel's blocks, and the words `/quant` and the panel share, in English and Chinese. */
final class Panel
{
    public const string ID = 'quant';

    public const array TITLE = ['en' => 'Quant dry run', 'zh-CN' => '量化模拟盘'];

    private const array TEXT = [
        'en' => [
            'running' => 'Running',
            'stopped' => 'Stopped',
            'problem' => 'Stopped on an error: {problem}',
            'retrying' => 'Cannot reach the stream (attempt {attempt}): {error} — trying again in {seconds}s',
            'not_started' => 'Not started. /quant start, or the Start button.',
            'equity' => 'Equity',
            'quote' => 'Cash',
            'realized' => 'Realized P&L',
            'positions' => 'Positions',
            'no_positions' => 'No open positions.',
            'trades' => 'Last trades',
            'no_trades' => 'No trades yet.',
            'start' => 'Start',
            'stop' => 'Stop',
            'reset' => 'Reset wallet',
            'reset_confirm' => 'Forget every paper trade and start again from the initial balance?',
            'setup' => '{exchange} · {symbols} · {interval} · {strategy} {parameters}',
            'stream' => 'Stream since {since}, {reconnects} reconnect(s)',
            'candles' => '{count} candles seen this session',
            'unrealized' => 'unrealized',
            'close' => 'Close',
            'close_confirm' => 'Sell the whole {symbol} position at the last price?',
        ],
        'zh-CN' => [
            'running' => '运行中',
            'stopped' => '已停止',
            'problem' => '因错误停止：{problem}',
            'retrying' => '连不上行情流（第 {attempt} 次）：{error}，{seconds} 秒后重试',
            'not_started' => '尚未启动。输入 /quant start 或点「启动」。',
            'equity' => '总权益',
            'quote' => '现金',
            'realized' => '已实现盈亏',
            'positions' => '持仓',
            'no_positions' => '没有持仓。',
            'trades' => '最近成交',
            'no_trades' => '还没有成交。',
            'start' => '启动',
            'stop' => '停止',
            'reset' => '重置钱包',
            'reset_confirm' => '清空所有模拟成交，从初始资金重新开始？',
            'setup' => '{exchange} · {symbols} · {interval} · {strategy} {parameters}',
            'stream' => '行情流自 {since} 起，重连 {reconnects} 次',
            'candles' => '本次会话已收到 {count} 根 K 线',
            'unrealized' => '浮动',
            'close' => '平仓',
            'close_confirm' => '按最新价卖出全部 {symbol} 持仓？',
        ],
    ];

    /** @param array<string, string|int|float> $vars */
    public static function say(string $locale, string $key, array $vars = []): string
    {
        $text = self::TEXT[$locale][$key] ?? self::TEXT['en'][$key] ?? $key;

        foreach ($vars as $name => $value) {
            $text = str_replace('{' . $name . '}', (string) $value, $text);
        }

        return $text;
    }

    /** @param array<string, mixed> $status what `Hub::status()` answers @return list<array<string, mixed>> */
    public static function blocks(array $status, string $locale): array
    {
        $quoteAsset = $status['quoteAsset'];
        $pnl = $status['equity'] - $status['initialQuote'];
        $blocks = [];

        if ($status['running'] && $status['retrying'] !== null) {
            $r = $status['retrying'];
            $blocks[] = ['type' => 'text', 'text' => self::say($locale, 'retrying', ['attempt' => $r['attempt'], 'error' => $r['error'], 'seconds' => (int) $r['nextIn']]), 'tone' => 'warning'];
        } elseif ($status['running']) {
            $blocks[] = ['type' => 'text', 'text' => self::say($locale, 'running') . ' · pid ' . $status['ownerPid'], 'tone' => 'success'];
        } elseif ($status['problem'] !== null) {
            $blocks[] = ['type' => 'text', 'text' => self::say($locale, 'problem', ['problem' => $status['problem']]), 'tone' => 'error'];
        } else {
            $blocks[] = ['type' => 'text', 'text' => self::say($locale, 'not_started'), 'tone' => 'muted'];
        }

        $blocks[] = ['type' => 'text', 'tone' => 'muted', 'text' => self::say($locale, 'setup', [
            'exchange' => $status['exchange'],
            'symbols' => implode(' ', $status['symbols']),
            'interval' => $status['interval'],
            'strategy' => $status['strategy'],
            'parameters' => json_encode($status['parameters']),
        ]) . ' · pid ' . $status['answeredBy']];

        if ($status['connectedAt'] !== null) {
            $blocks[] = ['type' => 'text', 'tone' => 'muted', 'text' => self::say($locale, 'stream', ['since' => date('H:i:s', $status['connectedAt']), 'reconnects' => $status['reconnects']])
                . ' · ' . self::say($locale, 'candles', ['count' => $status['candlesSeen']])];
        }

        $actions = $status['running']
            ? [['label' => self::say($locale, 'stop'), 'action' => 'stop']]
            : [['label' => self::say($locale, 'start'), 'action' => 'start']];
        $actions[] = ['label' => self::say($locale, 'reset'), 'action' => 'reset', 'tone' => 'danger', 'confirm' => self::say($locale, 'reset_confirm')];
        $blocks[] = ['type' => 'actions', 'actions' => $actions];

        $blocks[] = ['type' => 'card', 'title' => self::say($locale, 'equity'), 'blocks' => [
            ['type' => 'text', 'text' => sprintf('%s %s  (%s%s, %s%.2f%%)', self::money($status['equity']), $quoteAsset, $pnl >= 0 ? '+' : '', self::money($pnl), $pnl >= 0 ? '+' : '', $pnl / $status['initialQuote'] * 100), 'tone' => $pnl >= 0 ? 'success' : 'error'],
            ['type' => 'text', 'text' => self::say($locale, 'quote') . ': ' . self::money($status['quote']) . ' ' . $quoteAsset . ' · ' . self::say($locale, 'realized') . ': ' . self::money($status['realizedPnl']) . ' ' . $quoteAsset, 'tone' => 'muted'],
        ]];

        $positions = [];

        foreach ($status['positions'] as $p) {
            $positions[] = [
                'title' => sprintf('%s  %s @ %s', $p['symbol'], self::number($p['quantity']), self::money($p['averagePrice'])),
                'detail' => sprintf('%s %s%s %s  (%s)', self::money($p['price']), $p['unrealizedPnl'] >= 0 ? '+' : '', self::money($p['unrealizedPnl']), $quoteAsset, self::say($locale, 'unrealized')),
                'tone' => $p['unrealizedPnl'] >= 0 ? 'success' : 'error',
                'actions' => [['label' => self::say($locale, 'close'), 'action' => 'close', 'args' => ['symbol' => $p['symbol']], 'confirm' => self::say($locale, 'close_confirm', ['symbol' => $p['symbol']])]],
            ];
        }

        $blocks[] = ['type' => 'card', 'title' => self::say($locale, 'positions'), 'blocks' => [
            ['type' => 'list', 'items' => $positions, 'empty' => self::say($locale, 'no_positions')],
        ]];

        $trades = [];

        foreach (array_reverse($status['lastTrades']) as $trade) {
            $trade = Trade::fromArray($trade);
            $trades[] = [
                'title' => self::tradeLine($trade),
                'meta' => date('m-d H:i:s', intdiv($trade->time, 1000)),
                'tone' => $trade->pnl === null ? null : ($trade->pnl >= 0 ? 'success' : 'error'),
            ];
        }

        $blocks[] = ['type' => 'card', 'title' => self::say($locale, 'trades'), 'blocks' => [
            ['type' => 'list', 'items' => $trades, 'empty' => self::say($locale, 'no_trades')],
        ]];

        return $blocks;
    }

    /** One trade on one line, for the panel, `/quant trades` and the log. */
    public static function tradeLine(Trade $trade): string
    {
        $line = sprintf('%s %s %s @ %s (%s)', strtoupper($trade->side->value), $trade->symbol, self::number($trade->quantity), self::money($trade->price), $trade->reason);

        if ($trade->pnl !== null) {
            $line .= sprintf('  %s%s', $trade->pnl >= 0 ? '+' : '', self::money($trade->pnl));
        }

        return $line;
    }

    public static function money(float $value): string
    {
        return number_format($value, abs($value) >= 100 ? 2 : 4, '.', '');
    }

    public static function number(float $value): string
    {
        return rtrim(rtrim(sprintf('%.8f', $value), '0'), '.');
    }
}
