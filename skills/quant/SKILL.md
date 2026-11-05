---
name: quant
description: Dry-run crypto trading inside pig — a paper wallet fed by live Binance candles, with a moving-average strategy and risk limits. Use the quant_* tools when the person asks about the bot's state, positions, trades or candles, or asks you to place a paper order; use /quant to start or stop it. Nothing here touches a real exchange account.
---

# The dry-run trading engine

`pig-quant` runs a paper-trading bot on pig's own event loop: it streams candles from the exchange, hands every closed candle to the strategy, and fills the strategy's buys and sells in a wallet that exists only on this machine. There is no API key and no real order anywhere in it.

## Tools

- `quant_status` — whether the stream is running (and the error it stopped on, if any), the exchange, symbols, interval, strategy and its parameters, the risk limits, cash, equity, realized P&L, open positions with their unrealized P&L, last prices. Call it first.
- `quant_candles(symbol, limit)` — the last candles the engine holds; no network call. The newest one may still be forming (`closed: false`).
- `quant_trades(limit)` — the paper trades, oldest first, with the reason each was made (`ma-cross`, `stop-loss`, `manual`, `model`, or what you gave).
- `quant_backtest(symbol, interval, from, to?, overrides?)` — the strategy over past candles, on a fresh engine; `symbol` may be a basket (`BTC-USDT,ETH-USDT`), one wallet shared, judged against the equal-weight basket held; the dry-run wallet is untouched. Use it when the person asks how the strategy or a parameter would have done, and compare runs by their figures (return against buy & hold, max drawdown against buy & hold's, profit factor, fees), not by the return alone. Against buy & hold, run with `orderQuote: "all"` (and the two amount limits `"all"`): a fixed stake cannot compound and loses to holding in any rising market for that reason alone. A good past month proves nothing about the next one; say so when the person reads a result as a forecast.
- `quant_sweep(symbol, interval, from, to?, overrides)` — the backtest over every combination of parameter ranges (`"10..30:5"`, `"30,50,100"`), one row each, with the same figures. Use it before calling any parameter good: look at the neighbours of the best row. A good result whose neighbours lose is an overfit, not an edge; say so, and do not pick the best cell as the answer.
- `quant_funding(symbol, from, to?)` — what a cash-and-carry (long spot, short the perpetual) would have collected in funding over a range: gross, net of one round trip, annualized, on the capital both legs tie up, negative stretches, every month. Read the months, not the headline: a carry that earned its year in three months and paid out in the other nine is a bet on a regime, not an income. Say what the figures leave out — the basis at entry and exit, liquidation of the short in a spike, the exchange itself.
- `quant_spread(what, days?)` — the live gap between two exchanges (`status`), or the last days of it summarized (`report`). Read the report against its `thresholdBps`: minutes over it, how often, how long the stretches are. A gap that rarely clears the round-trip cost, and only for a minute, is not an arbitrage, however large its maximum once was; say so plainly.
- `quant_order(side, symbol, quote_amount?, quantity?, reason)` — a paper order at the last price, through the same risk limits the strategy has. It throws with the reason when refused (over the per-order limit, over the exposure limit, too many positions, not enough cash); say that reason to the person rather than trying a smaller order on your own.

## How to behave

- **It is a simulation.** Say so when the person asks how much "they" made: the numbers are a paper wallet's. Never describe a figure as money in an account.
- **Do not place orders the person did not ask for.** The strategy trades on its own; your `quant_order` is for what the person asks ("close BTC", "buy 50 USDT of ETH"). Put their words in `reason`.
- **Explain, do not predict.** When asked why the bot bought or sold, read `quant_trades` and `quant_candles` and explain it from the strategy's rule (a fast SMA crossing a slow one; a stop-loss under the average price). Do not forecast prices.
- **Starting and stopping** is `/quant start` and `/quant stop`, the person's to type (or the panel's buttons in the web UI). If `quant_status` says the engine stopped on an error, report the error as it is; it is usually the exchange's host being unreachable from here, which the person fixes in the settings (`quant.restUrl`, `quant.streamUrl`).
- **Settings** live under `quant` in pig's settings: `symbols`, `interval`, `initialQuote`, `orderQuote` (an amount, or `"all"` / `"50%"` of the free cash), `strategy` (`fast`, `slow`), `risk` (`maxOrderQuote` and `maxExposureQuote` — an amount or a share of the equity — `maxPositions`, `stopLossPct`). A change takes effect on the next session. Tell the person which key to change; do not edit the settings file for them unless asked.
