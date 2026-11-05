# pig-quant

Dry-run crypto trading in pure PHP, inside the pig coding agent: live Binance candles into a strategy, the strategy's buys and sells into a paper wallet, with risk limits in between. No API key, no real orders, no dependencies.

[中文](README.zh-CN.md)

## Installation

```bash
pig install git:github.com/owner888/pig-quant
```

Then, in pig:

```
/quant start
```

The first session writes the defaults to pig's settings under `quant` (see below); the engine does not start on its own unless `quant.autostart` is true.

## What it does

Every candle of every symbol arrives over the exchange's WebSocket stream. A closed candle goes to the strategy, which answers buy, sell or nothing; a buy passes the risk rules and is filled in the paper wallet at the candle's close plus slippage, minus the taker fee. A forming candle is checked against the stop-loss. The wallet — cash, positions, every trade — is saved to `~/.pig/agent/quant/state.json` after each trade and restored at the next start, so a dry run survives restarts (an incognito session saves nothing).

One engine, however many pig processes: `pig web` is a server plus a child process per conversation, and each loads the extension. The first process to start the engine (or to place an order) becomes its owner — it listens on a loopback port and writes `~/.pig/agent/quant/runner.json` — and every other process forwards `/quant`, the tools and the panel's buttons there, so the panel, the terminal and the model all see the same wallet. `/quant` says which process (pid) runs it. The file goes when the owner exits; a file whose process is gone is ignored.

The stream reconnects after the exchange's 24-hour disconnect or a network blip, filling the gap from REST and saying so in pig's log; a stream it cannot reach (a connect that times out, a refused connection) is tried again with a wait that doubles up to a minute, ten times, and `/quant` and the panel show the attempt. Anything else — a refused request, a geo-block page, an exchange error — stops the engine and is shown by `/quant` and in the panel; a dry run does not pretend to trade when it is not.

### Command

| `/quant` | |
| --- | --- |
| `status` (or nothing) | running or not, equity, cash, realized P&L, positions, stream uptime |
| `start` / `stop` | load history and start streaming / stop |
| `trades [n]` | the last n trades |
| `buy <symbol> [quote]` / `sell <symbol>` | a paper order at the last price, through the risk rules |
| `reset` | forget every trade, back to the initial balance (asks first) |
| `funding <symbol> <from> [to]` | what a cash-and-carry would have collected in funding — `/quant funding BTC-USDT 2024-11-01 2026-11-01` |
| `funding watch start` / `stop` / `status` | poll the current funding rates and say when one clears `funding.watch.alertAnnualizedPct` |
| `spread start` / `stop` / `status` / `report [days]` | watch the price gap between two exchanges (observation only) and summarize it |
| `backtest <symbol> <interval> <from> [to] [name=value…]` | run the strategy over past candles — `/quant backtest BTCUSDT 1m 2026-10-01 2026-11-01 fast=5 slow=20` |
| `sweep <symbol> <interval> <from> [to] name=a..b:step…` | the same backtest over every combination of parameter ranges, as a grid — `/quant sweep BTCUSDT 1d 2024-11-01 2026-11-01 fast=10..30:5 slow=30..100:10` |

### Tools

| Tool | What it does |
| --- | --- |
| `quant_status` | The engine's state: running, problem, exchange, symbols, strategy, risk limits, cash, equity, positions with unrealized P&L, last prices |
| `quant_candles` | The last candles held for a symbol (no network call) |
| `quant_trades` | The paper trades, with the reason for each |
| `quant_order` | A paper order through the risk rules; refused orders throw with the reason |
| `quant_backtest` | The strategy over past candles, with the report below |
| `quant_sweep` | Every combination of parameter ranges backtested over the same candles, one row each |
| `quant_funding` | A carry's funding over a range: gross, net of a round trip, annualized, negative stretches, every month |
| `quant_funding_watch` | The funding watcher: current rates, which are over the threshold and since when |
| `quant_spread` | The spread watcher's status, or its report over the last N days |

The `quant` skill tells the model it is a simulation, to place orders only when asked, and to explain the bot's trades from its rules rather than forecast prices.

### Web UI

With `pig web`, the sidebar's Extensions group has a **Quant dry run** panel: state, equity, positions (with a Close button each), the last trades, and Start / Stop / Reset. `/quant` in the page opens it.

## Backtesting

```
/quant backtest BTCUSDT 1m 2026-10-01 2026-11-01
/quant backtest ETHUSDT 15m 2026-06-01 fast=5 slow=20 stopLossPct=0.03
/quant backtest BTC-USDT 1d 2024-11-01 2026-11-01 orderQuote=all maxOrderQuote=all maxExposureQuote=all maxPositions=1
/quant backtest BTC-USDT,ETH-USDT,SOL-USDT 1d 2024-11-01 2026-11-01 orderQuote=all maxOrderQuote=all maxExposureQuote=all maxPositions=3
```

Dates are UTC; `to` defaults to now. The candles come from the exchange's REST endpoint a page at a time and every whole calendar month is kept under `~/.pig/agent/quant/candles/`, so the second run over the same months downloads nothing. `name=value` overrides a setting for this run only — `initialQuote`, `feeRate`, `slippage`, `orderQuote`, `fast`, `slow`, `maxOrderQuote`, `maxExposureQuote`, `maxPositions`, `stopLossPct`, `warmupCandles` — so parameters are compared without editing settings. Before the range, `warmupCandles` candles are seeded so the first candle can already be judged.

The run uses a fresh engine with the same wallet, risk rules and strategy code as the dry run; the dry run's wallet is not touched. Compare with buy & hold on the whole wallet (`orderQuote=all`), not on a fixed stake, or holding wins by compounding alone. Several symbols, comma-separated, are a basket: their candles are merged into one timeline through one engine — one wallet, `maxPositions` and the exposure limit shared — and buy & hold becomes the equal-weight basket; the report adds a line per symbol (closed trades, wins, P&L, that symbol held outright). A sweep takes a basket the same way. The report: final equity and return, the same range held outright (buy & hold) for comparison with its own max drawdown, the strategy's max drawdown, trades won and lost, win rate, profit factor, fees and their share of the initial balance, average holding time, a position still open at the end (valued at the last close, not sold), the trades, and an equity curve downsampled to 500 points. It is kept as JSON under `~/.pig/agent/quant/backtests/`, named by what it tested.

### Sweeping parameters

```
/quant sweep BTCUSDT 1d 2024-11-01 2026-11-01 fast=10..30:5 slow=30..100:10
/quant sweep BTCUSDT 4h 2026-01-01 stopLossPct=0.02,0.05,0.1 fast=20 slow=50
```

One good backtest proves little: a parameter can fit two years of one market by luck. A sweep runs the same backtest over every combination of the ranges given — `a..b:step` (the step defaults to 1), or a list `a,b,c`; a plain `name=value` holds that setting for every run — on candles fetched once, and prints the results together. Two swept parameters make a grid, rows for the first and columns for the second, each cell `return / profit factor`; any other number makes a list sorted by return. The last line counts how many combinations beat buy & hold and how many lost money. Read the shape, not the best cell: a good cell among good neighbours is a region worth more tests; a good cell among bad ones is an overfit. A combination the strategy refuses (a fast period not shorter than the slow one) is skipped and counted. At most 500 combinations. The summary is kept under `~/.pig/agent/quant/sweeps/`.

## What a carry would have earned

```
/quant funding BTC-USDT 2024-11-01 2026-11-01
/quant funding ETH-USDT 2025-01-01
```

A cash-and-carry holds the spot and shorts the perpetual for the same amount, so the price cancels out and what is left is the funding the perpetual pays its shorts every eight hours — or charges them, when the rate is negative. Before putting money behind that, measure it: the command fetches the perpetual's funding history (Binance USDⓈ-M, the one public venue with the whole history since 2019; `quant.funding.restUrl`), keeps whole months under `~/.pig/agent/quant/funding/`, and reports the funding collected gross and annualized, the same after one round trip (four legs: spot in and out at `feeRate`, perpetual in and out at `funding.perpFeeRate`, `slippage` on each), what that is a year on the capital both legs tie up (`funding.marginRatio`, the collateral behind the short as a share of its notional — 0.5 is 2x leverage), how many settlements were negative and the longest stretch of them, and every month's total four to a line, so a bad half-year shows. Nothing is traded.

Not in the figures: the basis at entry and exit (the perpetual above or below the spot, a few tenths of a percent either way), a short leg liquidated in a spike if the margin is thin, and the exchange itself. A carry swaps price risk for those.

Measured over two years the carry paid about 3% a year on capital, with a quarter of it in two bull-mania months — less than a treasury for more risk, except in those months. So the watcher: `/quant funding watch start` polls each symbol in `funding.watch.symbols` every `pollSeconds` (five minutes), and when a rate annualized (`rate × 3 × 365`) first clears `alertAnnualizedPct` (15% a year) it says so in the terminal that started it, logs it, and appends a line to `~/.pig/agent/quant/funding/alerts.jsonl`; the same once when the rate drops back, with how long it had been over. `status` shows every symbol's rate, its annualized figure, when it next settles and which are over. `funding.watch.autostart: true` starts it with every session on a machine left to watch. Like the dry run, one process polls and the others forward to it.

## Watching the spread between two exchanges

```
/quant spread start
/quant spread status
/quant spread report 3
```

Before building anything on the "arbitrage between exchanges" idea, measure it. The watcher opens the candle stream of the same symbol on two exchanges (`quant.spread`: `binance` and `okx`, `BTCUSDT`, `1m` by default), and for every minute both have closed writes one line to `~/.pig/agent/quant/spread/<a>-<b>-<symbol>-<day>.jsonl`: the two closes, the gap at the close in bps (1 bps = 0.01%; positive when the second exchange is dearer) and the widest gap seen inside the minute. Nothing is traded.

`report` summarizes the last N days: mean gap and which side is usually dearer, the |gap| at p50 / p90 / p99 and its maximum, the widest intra-minute gaps, and — against `thresholdBps`, what a round trip on both exchanges costs (30 bps by default: two fees of 0.1% and slippage) — how many minutes cleared it, how often, in how many stretches and how long the longest was. If those minutes are few and short, there is nothing for a bot at this cost structure to take. On a machine left to watch, `quant.spread.autostart: true` starts the watcher with every session.

## Settings

Under `quant` in `~/.pig/agent/settings.json`:

```json
{
  "exchange": "binance",
  "restUrl": "https://api.binance.com",
  "streamUrl": "wss://stream.binance.com:9443",
  "symbols": ["BTCUSDT"],
  "interval": "1m",
  "quoteAsset": "USDT",
  "initialQuote": 1000,
  "feeRate": 0.001,
  "slippage": 0.0005,
  "orderQuote": 100,
  "strategy": {"name": "ma-cross", "fast": 9, "slow": 21},
  "risk": {"maxOrderQuote": 100, "maxExposureQuote": 500, "maxPositions": 3, "stopLossPct": 0.02},
  "warmupCandles": 200,
  "autostart": false,
  "spread": {"exchanges": ["binance", "okx"], "symbol": "BTCUSDT", "interval": "1m", "thresholdBps": 30, "autostart": false},
  "funding": {"restUrl": "https://fapi.binance.com", "perpFeeRate": 0.0005, "marginRatio": 0.5,
              "watch": {"symbols": ["BTC-USDT", "ETH-USDT"], "alertAnnualizedPct": 0.15, "pollSeconds": 300, "autostart": false}}
}
```

- `symbols` — any number; each has its own series and position. `interval` is one of Binance's (`1m`, `5m`, `1h`, …).
- `orderQuote` — what one strategy buy spends: an amount (`100`), or a share of the free cash (`"50%"`, `"all"`) so that profits compound; a share is spread over the position slots still open, so `"all"` with `maxPositions: 3` puts a third of the cash in each of three symbols. `risk.maxOrderQuote` caps any buy and `maxExposureQuote` caps what is in positions at cost — each an amount, or a share of the equity (`"all"` lifts the cap); `maxPositions` caps how many symbols are held, `stopLossPct` (0.02 = 2%) closes a position under its average price; 0 turns the stop-loss off.
- `restUrl` / `streamUrl` — Binance keeps several hosts. Where `api.binance.com` is not reachable, `https://data-api.binance.vision` serves market data without a key; the spot testnet is `https://testnet.binance.vision` and `wss://stream.testnet.binance.vision`.
- `exchange` — `binance` (default) or `okx`. With `okx`, symbols are `BTC-USDT` (a `BTCUSDT` is split for you), the URL defaults become OKX's (`https://www.okx.com`, `wss://ws.okx.com:8443`), history is paged backward 100 candles at a time (slower than Binance's 1,000: a month of 1m candles is ~450 pages), and the stream is subscribed to by message and kept alive with a `ping` every 20 s.
- `strategy.name` — `ma-cross` (SMA crossover, `fast` and `slow` periods) is the one that ships.
- `funding` — for `/quant funding`: `restUrl` (Binance USDⓈ-M futures), `perpFeeRate` (one perpetual fill, 0.0005 = 0.05%), `marginRatio` (collateral behind the short as a share of its notional). `funding.watch` — the watcher: `symbols`, `alertAnnualizedPct` (0.15 = 15% a year), `pollSeconds` (at least 30), `autostart`.

A change takes effect at the next session.

## The engine on its own

`src/Quant/` is the engine and knows nothing of pig: candles, series, exchanges, strategies, the paper wallet and the risk rules, with no sockets and no clock. An exchange builds the request it wants sent and parses what comes back; whoever hosts the engine moves the bytes. pig's side is `src/PigQuant/`. The same package from Packagist (`composer require owner888/pig-quant`) gives another PHP program the `Quant\` namespace alone:

```php
$engine = new Quant\Engine(
    exchange: new Quant\Exchange\Binance(),
    strategy: new Quant\Strategy\MovingAverageCross(9, 21),
    wallet: new Quant\Wallet\DryRunWallet('USDT', 1000.0, feeRate: 0.001, slippage: 0.0005),
    risk: new Quant\Risk\RiskRules(maxOrderQuote: 100.0, maxExposureQuote: 500.0, maxPositions: 3, stopLossPct: 0.02),
    symbols: ['BTCUSDT'],
    interval: '1m',
    orderQuote: 100.0,
);
$engine->onEvent(fn (string $event, array $data) => print("$event\n"));
$engine->seed('BTCUSDT', $engine->exchange->parseCandles($bodyOfTheKlinesResponse));
$engine->onCandle('BTCUSDT', $engine->exchange->parseStreamMessage($message)->candle);
```

Another exchange is one class implementing `Quant\Exchange\Exchange` — pure functions over strings, tested from recorded payloads: how to spell a symbol, the candles request and how to read its answer, which way a time range is paged, the stream's URL, its subscription messages and its heartbeat, and how to read a stream message — and a line in `PigQuant\Config::exchange()`. `Binance` and `Okx` are the two that ship.

## Tests

```bash
PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml
```

The tests cover the engine only and need nothing from pig but its PHPUnit.

## Not yet

Real orders, charts, more exchanges and strategies. A real exchange is the wallet's interface over the exchange's order endpoints.

## License

MIT.
