<?php

declare(strict_types=1);

namespace Quant\Strategy;

use Quant\Market\Series;
use Quant\Market\Side;
use Quant\Wallet\Position;

/**
 * A strategy answers one question, once per closed candle: buy, sell, or nothing. Position
 * sizing, fees and risk limits are the engine's; a strategy only reads the series.
 */
interface Strategy
{
    public function name(): string;

    /** How many closed candles it needs before it can answer. */
    public function warmup(): int;

    /** @param Position|null $position what the wallet holds of the series' symbol, if anything */
    public function onCandle(Series $series, ?Position $position): ?Side;

    /** Its parameters, for status and logs. @return array<string, mixed> */
    public function parameters(): array;
}
