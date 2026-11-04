<?php

declare(strict_types=1);

namespace PigQuant;

use Pig\CodingAgent\Config as PigConfig;
use Quant\Wallet\DryRunWallet;

/**
 * The paper wallet between sessions: `~/.pig/agent/quant/state.json`. A dry run that forgot its
 * positions on every restart would never run 24 hours.
 */
final class State
{
    public static function path(): string
    {
        return PigConfig::home() . '/quant/state.json';
    }

    public static function save(DryRunWallet $wallet): void
    {
        $path = self::path();
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new \RuntimeException("Could not create {$directory}.");
        }

        $json = json_encode($wallet->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
        $tmp = $path . '.tmp';

        if (file_put_contents($tmp, $json . "\n") === false || !rename($tmp, $path)) {
            throw new \RuntimeException("Could not write {$path}.");
        }
    }

    /** Whether there was a saved wallet to restore. */
    public static function restore(DryRunWallet $wallet): bool
    {
        $path = self::path();

        if (!is_file($path)) {
            return false;
        }

        $json = file_get_contents($path);
        $data = is_string($json) ? json_decode($json, true) : null;

        if (!is_array($data)) {
            throw new \RuntimeException("{$path} is not the JSON this package writes; move it away to start over.");
        }

        $wallet->restore($data);

        return true;
    }

    public static function forget(): void
    {
        $path = self::path();

        if (is_file($path) && !unlink($path)) {
            throw new \RuntimeException("Could not remove {$path}.");
        }
    }
}
