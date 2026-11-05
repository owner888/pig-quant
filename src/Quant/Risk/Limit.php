<?php

declare(strict_types=1);

namespace Quant\Risk;

/**
 * A quote amount that is either fixed (`100`) or a share of what the wallet has (`"25%"`,
 * `"all"`). The order size and the two risk limits are all one of these, so a strategy can run
 * on a fixed stake the way it always has, or on the whole wallet so profits compound and a
 * backtest compares fairly with holding.
 *
 * What a share is a share *of* is the caller's: the order size takes a share of the free cash,
 * the risk limits a share of the equity.
 */
final readonly class Limit
{
    private function __construct(
        public ?float $quote,
        public ?float $share,
    ) {
    }

    public static function fixed(float $quote): self
    {
        if ($quote <= 0) {
            throw new \InvalidArgumentException("An amount is positive, not {$quote}.");
        }

        return new self($quote, null);
    }

    /** @param float $share a fraction in (0, 1] */
    public static function share(float $share): self
    {
        if ($share <= 0 || $share > 1) {
            throw new \InvalidArgumentException("A share is between 0 and 100%, not {$share}.");
        }

        return new self(null, $share);
    }

    /** `100`, `"100"`, `"25%"`, `"all"`, or a Limit already. */
    public static function parse(self|float|int|string $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $text = strtolower(trim($value));

            if ($text === 'all') {
                return self::share(1.0);
            }

            if (str_ends_with($text, '%')) {
                $number = substr($text, 0, -1);

                if (!is_numeric($number)) {
                    throw new \InvalidArgumentException("'{$value}' is not an amount: write 100, 25% or all.");
                }

                return self::share((float) $number / 100);
            }

            if (!is_numeric($text)) {
                throw new \InvalidArgumentException("'{$value}' is not an amount: write 100, 25% or all.");
            }

            return self::fixed((float) $text);
        }

        return self::fixed((float) $value);
    }

    /** True for `"25%"` or `"all"`, false for a number — without parsing twice. */
    public static function isShare(float|int|string $value): bool
    {
        return is_string($value) && (strtolower(trim($value)) === 'all' || str_ends_with(trim($value), '%'));
    }

    /** The amount, given what the share is of. */
    public function quote(float $of): float
    {
        return $this->quote ?? $of * $this->share;
    }

    /** `100`, `25%` or `all` — as it reads in settings and in a report's file name. */
    public function label(): string
    {
        if ($this->quote !== null) {
            return rtrim(rtrim(sprintf('%.6f', $this->quote), '0'), '.');
        }

        return $this->share >= 1.0 ? 'all' : rtrim(rtrim(sprintf('%.4f', $this->share * 100), '0'), '.') . '%';
    }

    /** What settings and JSON carry: a number for a fixed amount, the label for a share. */
    public function value(): float|string
    {
        return $this->quote ?? $this->label();
    }
}
