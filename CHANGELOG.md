# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## [0.4.0] - 2026-11-05

### Added

- Added parameter sweeps: `/quant sweep <symbol> <interval> <from> [to] fast=10..30:5 slow=30..100:10` and the `quant_sweep` tool run the same backtest over every combination of the ranges (`a..b:step`) or lists (`a,b,c`) given, on candles fetched once, and print a grid (two swept parameters) or a sorted list, each cell `return / profit factor`, with how many combinations beat buy & hold and how many lost. A combination the strategy refuses (`fast=30 slow=30`) is skipped and counted, not the end of the sweep. At most 500 combinations. The summary is kept under `~/.pig/agent/quant/sweeps/`. In the engine: `Quant\Backtest\Sweep` (ranges, combinations, the table); in pig: `PigQuant\Sweep`.
- The order size and the two amount limits may be a share: `"orderQuote": "all"` (or `"50%"`) spends that share of the free cash on every strategy buy, so profits compound and a backtest compares fairly with holding; `risk.maxOrderQuote` and `maxExposureQuote` take `"all"` / `"50%"` of the equity. Numbers still mean what they did. Backtest and sweep overrides accept the same (`orderQuote=all`). In the engine: `Quant\Risk\Limit`; `RiskRules::refuseBuy()` takes the equity.
- A backtest report carries buy & hold's own max drawdown (`buyAndHoldDrawdownPct`) next to the strategy's, so "less drawdown than holding" can be read off the report.

### Changed

- `PigQuant\Backtest::candles()` fetches a run's warm-up and range; the backtest and every run of a sweep share it.

## [0.3.0] - 2026-11-05

### Added

- Added OKX spot (`"exchange": "okx"`): `Quant\Exchange\Okx` — `BTC-USDT` symbols, `history-candles` paged backward 100 a page, the `candle<bar>` channel on the business WebSocket subscribed to by message and kept alive with `ping`. The `Exchange` interface grew what it needed: `pagesNewestFirst()`, `streamSubscriptions()`, `streamHeartbeat()`, `parseCandles()` taking the interval, and an exclusive `endTime`; `History` walks a range either way and `Runner` sends subscriptions and runs the heartbeat.

- Added the spread watcher: `/quant spread start | stop | status | report [days]` and the `quant_spread` tool open the same symbol's candle stream on two exchanges (`quant.spread`, Binance and OKX by default) and write every minute both closed to `~/.pig/agent/quant/spread/`: the two closes, the gap in bps and the widest gap inside the minute. The report says how wide the gap usually is and how often and how long it clears `thresholdBps`, what a round trip costs. Observation only. In the engine: `Quant\Spread\Observer`, `Sample`, `Stats`.
- The candle stream — connect, subscribe, heartbeat, receive, reopen with backoff — is one class, `PigQuant\Stream`, used by the dry run's runner and by the spread watcher's two streams.

### Fixed

- A backtest report's file name carries the order size, fee, slippage and stop-loss as well as the strategy parameters, so a run with `feeRate=0` no longer overwrites the one before it.

## [0.2.0] - 2026-11-05

### New Features

- Backtesting: `/quant backtest <symbol> <interval> <from> [to] [name=value…]` and the `quant_backtest` tool run the configured strategy over past candles — fetched from the exchange a page at a time, whole months cached under `~/.pig/agent/quant/candles/` — on a fresh engine with the dry run's wallet, risk rules and strategy code, and report final equity and return, buy & hold for comparison, max drawdown, trades won and lost, win rate, profit factor, fees, average hold, an open position at the end, the trades and a downsampled equity curve, kept as JSON under `~/.pig/agent/quant/backtests/`. `name=value` overrides a setting for one run. In the engine: `Quant\Backtest\Backtester` and `Report`, `Engine::equity()`, `Interval::milliseconds()`, and `Exchange::candlesRequest()` takes a start and end time.

### Changed

- History and warm-up requests go through pig's `Retrying::fetch()` (pig 1.6.1 or later) instead of loops of their own: a page the network loses or a 429/5xx is asked for again, three times with a growing wait; a 4xx is not.
- A month the backtest range only touches — the warm-up before it, the month in progress — is fetched for the part asked and not cached; only whole, finished months are kept.

## [0.1.0] - 2026-11-04

### New Features

- The engine (`Quant\`): candles and series, a Binance spot exchange (klines over REST, combined kline stream over WebSocket), the `Strategy` interface with an SMA crossover (`ma-cross`), a paper wallet with fee and slippage, risk rules (per-order and exposure caps, position count, stop-loss) and the `Engine` that runs them over closed candles. Pure PHP, no sockets, no dependencies.
- pig's side (`PigQuant\`): `/quant status | start | stop | trades | buy | sell | reset`, the `quant_status`, `quant_candles`, `quant_trades` and `quant_order` tools, a web UI panel, settings under `quant` with defaults written on first start, the wallet saved to `~/.pig/agent/quant/state.json` after every trade, and a runner that streams on pig's event loop and reconnects when the exchange closes the stream.
- One engine across pig's processes: the first to start it (or to order) owns it, on a loopback port recorded in `~/.pig/agent/quant/runner.json`; `/quant`, the tools and the panel in every other process forward there, so `pig web`'s server, its conversation children and the terminal see one wallet.
- A stream that cannot be reached is retried with a wait that doubles up to a minute, ten times; the exchange getting something wrong still stops the runner at once.
- The `quant` skill: the model is told it is a simulation, to order only when asked and to explain trades from the rules.
