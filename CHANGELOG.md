# Changelog

What changed, newest first, in pig's format: `## [x.y.z] - YYYY-MM-DD`, then only the sections that
have entries (`### New Features`, `### Added`, `### Changed`, `### Fixed`). Notes under
`## Unreleased` belong to no version yet; the version itself lives in the git tag.

## [0.1.0] - 2026-11-04

### New Features

- The engine (`Quant\`): candles and series, a Binance spot exchange (klines over REST, combined kline stream over WebSocket), the `Strategy` interface with an SMA crossover (`ma-cross`), a paper wallet with fee and slippage, risk rules (per-order and exposure caps, position count, stop-loss) and the `Engine` that runs them over closed candles. Pure PHP, no sockets, no dependencies.
- pig's side (`PigQuant\`): `/quant status | start | stop | trades | buy | sell | reset`, the `quant_status`, `quant_candles`, `quant_trades` and `quant_order` tools, a web UI panel, settings under `quant` with defaults written on first start, the wallet saved to `~/.pig/agent/quant/state.json` after every trade, and a runner that streams on pig's event loop and reconnects when the exchange closes the stream.
- One engine across pig's processes: the first to start it (or to order) owns it, on a loopback port recorded in `~/.pig/agent/quant/runner.json`; `/quant`, the tools and the panel in every other process forward there, so `pig web`'s server, its conversation children and the terminal see one wallet.
- A stream that cannot be reached is retried with a wait that doubles up to a minute, ten times; the exchange getting something wrong still stops the runner at once.
- The `quant` skill: the model is told it is a simulation, to order only when asked and to explain trades from the rules.
