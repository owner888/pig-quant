# pig-quant

纯 PHP 的加密货币模拟交易（dry run），跑在 pig coding agent 里：Binance 实时 K 线进策略，策略的买卖进模拟钱包，中间过一层风控。没有 API key，不下真实订单，零依赖。

[English](README.md)

## 安装

```bash
pig install git:github.com/owner888/pig-quant
```

然后在 pig 里：

```
/quant start
```

第一次会话会把默认配置写进 pig 设置的 `quant` 块（见下）；除非 `quant.autostart` 为 true，引擎不会自己启动。

## 它做什么

每个交易对的每根 K 线通过交易所的 WebSocket 流进来。收盘的 K 线交给策略，策略回答买、卖或不动；买单先过风控，再在模拟钱包里按收盘价加滑点、扣 taker 手续费成交。未收盘的 K 线只用来检查止损。钱包——现金、持仓、每笔成交——每成交一次就保存到 `~/.pig/agent/quant/state.json`，下次启动恢复，所以模拟盘能跨重启跑下去（无痕会话不保存）。

不管开了多少个 pig 进程，引擎只有一个：`pig web` 是一个服务进程加每个会话一个子进程，每个都会加载扩展。最先启动引擎（或最先下单）的进程成为 owner——它在本机回环地址监听一个端口，并写入 `~/.pig/agent/quant/runner.json`——其他进程的 `/quant`、工具和面板按钮全部转发到它那里，所以面板、终端、模型看到的是同一个钱包。`/quant` 会显示引擎跑在哪个进程（pid）。owner 退出时文件删除；进程已不在的文件会被忽略。

交易所每 24 小时断一次连接、网络抖一下，流会自动重连并用 REST 补齐缺口，并写进 pig 的日志；连不上（握手超时、连接被拒）则按 3 秒起、每次翻倍、最长 60 秒的间隔重试，连续 10 次失败才停，`/quant` 和面板会显示正在第几次重试。其他情况——请求被拒、地区限制页面、交易所报错——引擎停下，`/quant` 和面板都会显示错误：模拟盘不会在没在交易的时候假装在交易。

### 命令

| `/quant` | |
| --- | --- |
| `status`（或不带参数） | 是否运行、权益、现金、已实现盈亏、持仓、行情流在线时长 |
| `start` / `stop` | 加载历史并开始接收行情 / 停止 |
| `trades [n]` | 最近 n 笔成交 |
| `buy <symbol> [quote]` / `sell <symbol>` | 按最新价下一笔模拟单，同样过风控 |
| `reset` | 清空所有成交，回到初始资金（先确认） |
| `backtest <symbol> <interval> <from> [to] [name=value…]` | 用历史 K 线跑策略——`/quant backtest BTCUSDT 1m 2026-10-01 2026-11-01 fast=5 slow=20` |

### 工具

| 工具 | 作用 |
| --- | --- |
| `quant_status` | 引擎状态：是否运行、错误、交易所、交易对、策略、风控、现金、权益、持仓及浮动盈亏、最新价 |
| `quant_candles` | 某交易对当前持有的最近 K 线（不发网络请求） |
| `quant_trades` | 模拟成交记录，带每笔的原因 |
| `quant_order` | 过风控的模拟下单；被拒时抛出原因 |
| `quant_backtest` | 用历史 K 线跑策略，返回下面说的报告 |

`quant` skill 告诉模型：这是模拟盘；只在用户要求时下单；解释机器人的成交要从策略规则出发，不要预测价格。

### Web UI

`pig web` 的侧边栏 Extensions 组里有一个「量化模拟盘」面板：状态、权益、持仓（每个有平仓按钮）、最近成交，以及启动 / 停止 / 重置。页面里输入 `/quant` 可打开它。

## 回测

```
/quant backtest BTCUSDT 1m 2026-10-01 2026-11-01
/quant backtest ETHUSDT 15m 2026-06-01 fast=5 slow=20 stopLossPct=0.03
```

日期按 UTC；`to` 省略即到现在。K 线通过交易所 REST 分页拉取，整月的数据缓存在 `~/.pig/agent/quant/candles/`，同区间第二次跑不再下载。`name=value` 只覆盖本次运行的设置——`initialQuote`、`feeRate`、`slippage`、`orderQuote`、`fast`、`slow`、`maxOrderQuote`、`maxExposureQuote`、`maxPositions`、`stopLossPct`、`warmupCandles`——换参数对比不用改设置文件。区间之前会先灌入 `warmupCandles` 根 K 线预热，第一根就能判断。

回测用一个全新的引擎，钱包、风控、策略和模拟盘是同一套代码；模拟盘的钱包不受影响。报告包含：最终权益和收益率、同区间「买入持有」的收益作对照、最大回撤、盈亏笔数、胜率、盈亏比、手续费及其占初始资金的比例、平均持仓时长、结束时仍未平的仓位（按最后收盘价估值，不强制卖出）、成交列表，以及降采样到 500 点的权益曲线。结果以 JSON 存在 `~/.pig/agent/quant/backtests/`，文件名就是测试的内容。

## 配置

`~/.pig/agent/settings.json` 里的 `quant`：

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

- `symbols`——任意多个，各自独立的 K 线序列和持仓。`interval` 用 Binance 的写法（`1m`、`5m`、`1h`……）。
- `orderQuote`——策略每次买入花多少。`risk.maxOrderQuote` 限制单笔买入上限，`maxExposureQuote` 限制持仓成本总额，`maxPositions` 限制同时持有的交易对数，`stopLossPct`（0.02 = 2%）在均价下方平仓，0 表示关闭止损。
- `restUrl` / `streamUrl`——Binance 有多个域名。`api.binance.com` 不通的地方可用 `https://data-api.binance.vision`（行情数据不需要 key）；现货测试网是 `https://testnet.binance.vision` 和 `wss://stream.testnet.binance.vision`。
- `strategy.name`——内置 `ma-cross`（SMA 金叉死叉，`fast`、`slow` 周期）。

改动在下一次会话生效。

## 引擎可以单独用

`src/Quant/` 是引擎，不知道 pig 的存在：K 线、序列、交易所、策略、模拟钱包、风控，不开 socket、不读时钟。交易所只负责「构造要发的请求」和「解析回来的内容」，字节由宿主搬运。pig 这边的代码在 `src/PigQuant/`。同一个包发到 Packagist 后（`composer require owner888/pig-quant`），别的 PHP 程序只用 `Quant\` 命名空间即可：

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
$engine->seed('BTCUSDT', $engine->exchange->parseCandles($klinesResponseBody));
$engine->onCandle('BTCUSDT', $engine->exchange->parseStreamMessage($message)->candle);
```

新增交易所 = 一个实现 `Quant\Exchange\Exchange` 的类（五个方法，全是纯函数，用录下来的报文做测试）+ `PigQuant\Config::exchange()` 里加一行。

## 测试

```bash
PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml
```

测试只覆盖引擎，除了 pig 自带的 PHPUnit 什么都不需要。

## 还没做

真实下单、图表、更多交易所和策略。真实交易是钱包接口对接交易所的下单端点。

## 许可

MIT。
