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
| `funding <symbol> <from> [to]` | 现货多 + 永续空的「期现套利」在这段时间能收到多少资金费——`/quant funding BTC-USDT 2024-11-01 2026-11-01` |
| `spread start` / `stop` / `status` / `report [days]` | 观察两个交易所之间的价差（只观察不交易）并汇总 |
| `backtest <symbol> <interval> <from> [to] [name=value…]` | 用历史 K 线跑策略——`/quant backtest BTCUSDT 1m 2026-10-01 2026-11-01 fast=5 slow=20` |
| `sweep <symbol> <interval> <from> [to] name=a..b:step…` | 同一个回测跑遍参数区间的每个组合，输出网格——`/quant sweep BTCUSDT 1d 2024-11-01 2026-11-01 fast=10..30:5 slow=30..100:10` |

### 工具

| 工具 | 作用 |
| --- | --- |
| `quant_status` | 引擎状态：是否运行、错误、交易所、交易对、策略、风控、现金、权益、持仓及浮动盈亏、最新价 |
| `quant_candles` | 某交易对当前持有的最近 K 线（不发网络请求） |
| `quant_trades` | 模拟成交记录，带每笔的原因 |
| `quant_order` | 过风控的模拟下单；被拒时抛出原因 |
| `quant_backtest` | 用历史 K 线跑策略，返回下面说的报告 |
| `quant_sweep` | 参数区间的每个组合在同一段 K 线上各跑一次回测，每个组合一行 |
| `quant_funding` | 一段时间的资金费收益：毛收益、扣一次进出后的净收益、年化、为负的时段、每个月 |
| `quant_spread` | 价差观察器的状态，或最近 N 天的汇总报告 |

`quant` skill 告诉模型：这是模拟盘；只在用户要求时下单；解释机器人的成交要从策略规则出发，不要预测价格。

### Web UI

`pig web` 的侧边栏 Extensions 组里有一个「量化模拟盘」面板：状态、权益、持仓（每个有平仓按钮）、最近成交，以及启动 / 停止 / 重置。页面里输入 `/quant` 可打开它。

## 回测

```
/quant backtest BTCUSDT 1m 2026-10-01 2026-11-01
/quant backtest ETHUSDT 15m 2026-06-01 fast=5 slow=20 stopLossPct=0.03
/quant backtest BTC-USDT 1d 2024-11-01 2026-11-01 orderQuote=all maxOrderQuote=all maxExposureQuote=all maxPositions=1
/quant backtest BTC-USDT,ETH-USDT,SOL-USDT 1d 2024-11-01 2026-11-01 orderQuote=all maxOrderQuote=all maxExposureQuote=all maxPositions=3
```

日期按 UTC；`to` 省略即到现在。K 线通过交易所 REST 分页拉取，整月的数据缓存在 `~/.pig/agent/quant/candles/`，同区间第二次跑不再下载。`name=value` 只覆盖本次运行的设置——`initialQuote`、`feeRate`、`slippage`、`orderQuote`、`fast`、`slow`、`maxOrderQuote`、`maxExposureQuote`、`maxPositions`、`stopLossPct`、`warmupCandles`——换参数对比不用改设置文件；`orderQuote`、`maxOrderQuote`、`maxExposureQuote` 也可以写 `all` 或 `50%`。区间之前会先灌入 `warmupCandles` 根 K 线预热，第一根就能判断。

和买入持有比较时要用整个钱包（`orderQuote=all`）而不是固定金额，否则持币光靠复利就赢了。多个交易对用逗号隔开就是一篮子：各自的 K 线合并成一条时间轴喂给同一个引擎——钱包、`maxPositions`、敞口上限都共享——「买入持有」变成等权篮子；报告每个币多一行（平仓笔数、赢的笔数、盈亏、该币单独持有的收益）。扫描同样接受篮子。回测用一个全新的引擎，钱包、风控、策略和模拟盘是同一套代码；模拟盘的钱包不受影响。报告包含：最终权益和收益率、同区间「买入持有」的收益及其自身最大回撤作对照、策略的最大回撤、盈亏笔数、胜率、盈亏比、手续费及其占初始资金的比例、平均持仓时长、结束时仍未平的仓位（按最后收盘价估值，不强制卖出）、成交列表，以及降采样到 500 点的权益曲线。结果以 JSON 存在 `~/.pig/agent/quant/backtests/`，文件名就是测试的内容。

### 参数扫描

```
/quant sweep BTCUSDT 1d 2024-11-01 2026-11-01 fast=10..30:5 slow=30..100:10
/quant sweep BTCUSDT 4h 2026-01-01 stopLossPct=0.02,0.05,0.1 fast=20 slow=50
```

一次漂亮的回测说明不了什么：一组参数碰巧贴合某个市场的两年并不稀奇。扫描把同一个回测跑遍给定区间的每个组合——`a..b:step`（步长省略即 1），或列表 `a,b,c`；普通的 `name=value` 对每次运行都固定——K 线只拉一次，结果放在一起看。扫两个参数时输出网格，第一个参数是行、第二个是列，每格是「收益 / 盈亏比」；其他数量则按收益排序列出。最后一行统计多少个组合跑赢买入持有、多少个亏钱。要看的是形状而不是最好的那一格：好格子周围也好，说明这片区域值得继续测；好格子周围都差，就是过拟合。策略拒绝的组合（快线周期不短于慢线）会跳过并计数。最多 500 个组合。汇总保存在 `~/.pig/agent/quant/sweeps/`。

## 期现套利能赚多少

```
/quant funding BTC-USDT 2024-11-01 2026-11-01
/quant funding ETH-USDT 2025-01-01
```

现货买一份、永续合约空一份，价格涨跌互相抵消，剩下的就是永续每 8 小时付给空头的资金费——费率为负时则是空头付钱。投钱之前先量一量：命令拉取永续的资金费率历史（数据源是 Binance U 本位合约，唯一公开 2019 年至今全量的交易所；`quant.funding.restUrl`），整月缓存在 `~/.pig/agent/quant/funding/`，报告：收到的资金费毛收益及年化、扣掉一次进出（四条腿：现货买卖各按 `feeRate`，永续开平各按 `funding.perpFeeRate`，每条腿加 `slippage`）后的净收益、折算到两条腿占用资金上的年化（`funding.marginRatio`，空头腿后面押的保证金占名义的比例，0.5 即 2 倍杠杆）、为负的结算次数和最长连续时间、每个月的合计（一行四个月，半年不行一眼就看见）。不会下单。

没算进去的：进出场时的基差（永续比现货高或低，正负几个千分点）、保证金太薄时空头腿被插针强平、以及交易所本身。套利是把价格风险换成了这几样。

## 观察两个交易所之间的价差

```
/quant spread start
/quant spread status
/quant spread report 3
```

在「跨交易所套利」这个想法上投入任何东西之前，先量一量。观察器同时打开两个交易所同一交易对的 K 线流（`quant.spread`，默认 `binance` 和 `okx`、`BTCUSDT`、`1m`），每当两边都收盘了同一分钟，就往 `~/.pig/agent/quant/spread/<a>-<b>-<symbol>-<day>.jsonl` 写一行：两边收盘价、收盘时的价差（bps，1 bps = 0.01%，第二家更贵为正）、以及这一分钟内出现过的最大价差。不交易。

`report` 汇总最近 N 天：平均价差和通常哪边更贵、|价差| 的 p50 / p90 / p99 和最大值、分钟内最大价差，以及——对照 `thresholdBps`，即在两家各做一次所需的来回成本（默认 30 bps：两次 0.1% 手续费加滑点）——有多少分钟超过了它、占比多少、分成几段、最长一段多少分钟。如果超过阈值的分钟又少又短，在这个成本结构下机器人就没有肉可吃。放在服务器上挂着的话，`quant.spread.autostart: true` 会让每次会话启动时自动开始观察。

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
  "autostart": false,
  "spread": {"exchanges": ["binance", "okx"], "symbol": "BTCUSDT", "interval": "1m", "thresholdBps": 30, "autostart": false},
  "funding": {"restUrl": "https://fapi.binance.com", "perpFeeRate": 0.0005, "marginRatio": 0.5}
}
```

- `symbols`——任意多个，各自独立的 K 线序列和持仓。`interval` 用 Binance 的写法（`1m`、`5m`、`1h`……）。
- `orderQuote`——策略每次买入花多少：一个金额（`100`），或可用现金的比例（`"50%"`、`"all"`），后者让利润复投；比例会按还空着的仓位数平摊，`"all"` 配 `maxPositions: 3` 就是三个币各占三分之一。`risk.maxOrderQuote` 限制单笔买入上限，`maxExposureQuote` 限制持仓成本总额——各自是金额或权益的比例（`"all"` 即不限）；`maxPositions` 限制同时持有的交易对数，`stopLossPct`（0.02 = 2%）在均价下方平仓，0 表示关闭止损。
- `restUrl` / `streamUrl`——Binance 有多个域名。`api.binance.com` 不通的地方可用 `https://data-api.binance.vision`（行情数据不需要 key）；现货测试网是 `https://testnet.binance.vision` 和 `wss://stream.testnet.binance.vision`。
- `exchange`——`binance`（默认）或 `okx`。选 `okx` 时交易对写 `BTC-USDT`（写 `BTCUSDT` 会自动拆），URL 默认换成 OKX 的（`https://www.okx.com`、`wss://ws.okx.com:8443`），历史 K 线从新到旧倒着翻页、每页 100 根（比 Binance 的 1000 慢，1m 一个月约 450 页），行情流连上后发订阅消息、每 20 秒发一次 `ping` 保活。
- `strategy.name`——内置 `ma-cross`（SMA 金叉死叉，`fast`、`slow` 周期）。
- `funding`——给 `/quant funding` 用：`restUrl`（Binance U 本位合约）、`perpFeeRate`（永续一次成交的手续费，0.0005 = 0.05%）、`marginRatio`（空头腿后面押的保证金占名义的比例）。

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

新增交易所 = 一个实现 `Quant\Exchange\Exchange` 的类（全是对字符串的纯函数，用录下来的报文做测试：交易对怎么写、K 线请求怎么构造和怎么读、时间区间往哪个方向翻页、流的 URL、订阅消息、心跳、流消息怎么读）+ `PigQuant\Config::exchange()` 里加一行。内置 `Binance` 和 `Okx` 两个。

## 测试

```bash
PIG_DIR=~/Development/owner/pig ~/Development/owner/pig/vendor/bin/phpunit -c phpunit.xml
```

测试只覆盖引擎，除了 pig 自带的 PHPUnit 什么都不需要。

## 还没做

真实下单、图表、更多交易所和策略。真实交易是钱包接口对接交易所的下单端点。

## 许可

MIT。
