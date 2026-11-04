<?php

declare(strict_types=1);

namespace Quant\Market;

/**
 * The candles of one symbol at one interval, oldest first, keyed by open time: a candle that
 * arrives again (the stream sends the forming candle on every tick) replaces the one it had.
 */
final class Series
{
    /** @var array<int, Candle> openTime => candle, in order */
    private array $candles = [];

    public function __construct(
        public readonly string $symbol,
        public readonly string $interval,
        private readonly int $keep = 1000,
    ) {
    }

    public function add(Candle $candle): void
    {
        $known = isset($this->candles[$candle->openTime]);
        $last = array_key_last($this->candles);
        $this->candles[$candle->openTime] = $candle;

        if (!$known) {
            if ($last !== null && $candle->openTime < $last) {
                ksort($this->candles);
            }

            while (count($this->candles) > $this->keep) {
                unset($this->candles[array_key_first($this->candles)]);
            }
        }
    }

    /** @param list<Candle> $candles */
    public function addAll(array $candles): void
    {
        foreach ($candles as $candle) {
            $this->add($candle);
        }
    }

    public function count(): int
    {
        return count($this->candles);
    }

    public function last(): ?Candle
    {
        $key = array_key_last($this->candles);

        return $key === null ? null : $this->candles[$key];
    }

    /** @return list<Candle> */
    public function all(): array
    {
        return array_values($this->candles);
    }

    /** Closed candles only, oldest first: what a strategy decides on. @return list<Candle> */
    public function closed(): array
    {
        return array_values(array_filter($this->candles, static fn (Candle $c): bool => $c->closed));
    }

    /** Close prices of the closed candles, oldest first. @return list<float> */
    public function closes(): array
    {
        return array_map(static fn (Candle $c): float => $c->close, $this->closed());
    }
}
