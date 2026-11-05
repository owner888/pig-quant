<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\CodingAgent\Settings;
use Quant\Engine;
use Quant\Exchange\Binance;
use Quant\Exchange\Exchange;
use Quant\Exchange\Okx;
use Quant\Risk\RiskRules;
use Quant\Strategy\MovingAverageCross;
use Quant\Strategy\Strategy;
use Quant\Wallet\DryRunWallet;

/**
 * The `quant` block of pig's settings, with its defaults, and the engine it describes.
 *
 * ```json
 * "quant": {
 *   "exchange": "binance",
 *   "restUrl": "https://api.binance.com",
 *   "streamUrl": "wss://stream.binance.com:9443",
 *   "symbols": ["BTCUSDT"],
 *   "interval": "1m",
 *   "quoteAsset": "USDT",
 *   "initialQuote": 1000,
 *   "feeRate": 0.001,
 *   "slippage": 0.0005,
 *   "orderQuote": 100,
 *   "strategy": {"name": "ma-cross", "fast": 9, "slow": 21},
 *   "risk": {"maxOrderQuote": 100, "maxExposureQuote": 500, "maxPositions": 3, "stopLossPct": 0.02},
 *   "warmupCandles": 200,
 *   "autostart": false,
 *   "spread": {"exchanges": ["binance", "okx"], "symbol": "BTCUSDT", "interval": "1m", "thresholdBps": 30, "autostart": false}
 * }
 * ```
 */
final class Config
{
    public const array DEFAULTS = [
        'exchange' => 'binance',
        'restUrl' => Binance::REST,
        'streamUrl' => Binance::STREAM,
        'symbols' => ['BTCUSDT'],
        'interval' => '1m',
        'quoteAsset' => 'USDT',
        'initialQuote' => 1000.0,
        'feeRate' => 0.001,
        'slippage' => 0.0005,
        'orderQuote' => 100.0,
        'strategy' => ['name' => 'ma-cross', 'fast' => 9, 'slow' => 21],
        'risk' => ['maxOrderQuote' => 100.0, 'maxExposureQuote' => 500.0, 'maxPositions' => 3, 'stopLossPct' => 0.02],
        'warmupCandles' => 200,
        'autostart' => false,
        'spread' => ['exchanges' => ['binance', 'okx'], 'symbol' => 'BTCUSDT', 'interval' => '1m', 'thresholdBps' => 30.0, 'autostart' => false],
    ];

    /** The settings' `quant` block over the defaults. @return array<string, mixed> */
    public static function load(?Settings $settings): array
    {
        $given = $settings?->get('quant');
        $given = is_array($given) ? $given : [];
        $config = array_replace(self::DEFAULTS, $given);
        $config['strategy'] = array_replace(self::DEFAULTS['strategy'], is_array($given['strategy'] ?? null) ? $given['strategy'] : []);
        $config['risk'] = array_replace(self::DEFAULTS['risk'], is_array($given['risk'] ?? null) ? $given['risk'] : []);
        $config['spread'] = array_replace(self::DEFAULTS['spread'], is_array($given['spread'] ?? null) ? $given['spread'] : []);
        $config['symbols'] = array_values(array_map('strval', (array) $config['symbols']));

        // The URL defaults written to the settings are Binance's; `exchange: okx` with those
        // left as they were means OKX's own, not Binance's hosts asked for OKX's paths.
        if ($config['exchange'] === 'okx') {
            $config['restUrl'] = in_array($config['restUrl'], ['', Binance::REST], true) ? Okx::REST : $config['restUrl'];
            $config['streamUrl'] = in_array($config['streamUrl'], ['', Binance::STREAM], true) ? Okx::STREAM : $config['streamUrl'];
        }

        return $config;
    }

    /** Write the defaults the person has not set, so the settings file shows what there is to change. */
    public static function writeDefaults(?Settings $settings): void
    {
        if ($settings === null || is_array($settings->get('quant'))) {
            return;
        }

        $settings->set('quant', self::DEFAULTS);
    }

    /** @param array<string, mixed> $config */
    public static function engine(array $config): Engine
    {
        return new Engine(
            exchange: self::exchange($config),
            strategy: self::strategy($config['strategy']),
            wallet: new DryRunWallet((string) $config['quoteAsset'], (float) $config['initialQuote'], (float) $config['feeRate'], (float) $config['slippage']),
            risk: new RiskRules((float) $config['risk']['maxOrderQuote'], (float) $config['risk']['maxExposureQuote'], (int) $config['risk']['maxPositions'], (float) $config['risk']['stopLossPct']),
            symbols: $config['symbols'],
            interval: (string) $config['interval'],
            orderQuote: (float) $config['orderQuote'],
        );
    }

    /**
     * The spread watcher the `spread` block describes: two exchanges by name, on their own hosts.
     *
     * @param array<string, mixed> $config
     */
    public static function spreadRunner(array $config): SpreadRunner
    {
        $spread = $config['spread'];
        $names = array_values(array_map('strval', (array) $spread['exchanges']));

        if (count($names) !== 2) {
            throw new \InvalidArgumentException('quant.spread.exchanges names two exchanges.');
        }

        return new SpreadRunner(
            self::exchangeNamed($names[0]),
            self::exchangeNamed($names[1]),
            (string) $spread['symbol'],
            (string) $spread['interval'],
            (float) $spread['thresholdBps'],
        );
    }

    /** An exchange by name on its default hosts. */
    public static function exchangeNamed(string $name): Exchange
    {
        return match ($name) {
            'binance' => new Binance(),
            'okx' => new Okx(),
            default => throw new \InvalidArgumentException("No exchange '{$name}'; there is: binance, okx."),
        };
    }

    /** @param array<string, mixed> $config */
    public static function exchange(array $config): Exchange
    {
        return match ($config['exchange']) {
            'binance' => new Binance((string) $config['restUrl'], (string) $config['streamUrl']),
            'okx' => new Okx((string) $config['restUrl'], (string) $config['streamUrl']),
            default => throw new \InvalidArgumentException("No exchange '{$config['exchange']}'; there is: binance, okx."),
        };
    }

    /** @param array<string, mixed> $config */
    public static function strategy(array $config): Strategy
    {
        return match ($config['name']) {
            'ma-cross' => new MovingAverageCross((int) $config['fast'], (int) $config['slow']),
            default => throw new \InvalidArgumentException("No strategy '{$config['name']}'; there is: ma-cross."),
        };
    }
}
