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

### Tools

| Tool | What it does |
| --- | --- |
| `quant_status` | The engine's state: running, problem, exchange, symbols, strategy, risk limits, cash, equity, positions with unrealized P&L, last prices |
| `quant_candles` | The last candles held for a symbol (no network call) |
| `quant_trades` | The paper trades, with the reason for each |
| `quant_order` | A paper order through the risk rules; refused orders throw with the reason |

The `quant` skill tells the model it is a simulation, to place orders only when asked, and to explain the bot's trades from its rules rather than forecast prices.

### Web UI

With `pig web`, the sidebar's Extensions group has a **Quant dry run** panel: state, equity, positions (with a Close button each), the last trades, and Start / Stop / Reset. `/quant` in the page opens it.

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
  "autostart": false
}
```

- `symbols` — any number; each has its own series and position. `interval` is one of Binance's (`1m`, `5m`, `1h`, …).
- `orderQuote` — what one strategy buy spends. `risk.maxOrderQuote` caps any buy, `maxExposureQuote` caps what is in positions at cost, `maxPositions` caps how many symbols are held, `stopLossPct` (0.02 = 2%) closes a position under its average price; 0 turns the stop-loss off.
- `restUrl` / `streamUrl` — Binance keeps several hosts. Where `api.binance.com` is not reachable, `https://data-api.binance.vision` serves market data without a key; the spot testnet is `https://testnet.binance.vision` and `wss://stream.testnet.binance.vision`.
- `strategy.name` — `ma-cross` (SMA crossover, `fast` and `slow` periods) is the one that ships.

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

Another exchange is one class implementing `Quant\Exchange\Exchange` — five methods, all pure, tested from recorded payloads — and a line in `PigQuant\Config::exchange()`.

## Tests

```bash
PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml
```

The tests cover the engine only and need nothing from pig but its PHPUnit.

## Not yet

Backtesting, real orders, charts, more exchanges and strategies. The engine is shaped for them: a backtest is `seed()` plus `onCandle()` over history; a real exchange is the wallet's interface over the exchange's order endpoints.

## License

MIT.
