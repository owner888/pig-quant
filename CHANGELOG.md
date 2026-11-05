# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

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
