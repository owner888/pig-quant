<?php

declare(strict_types=1);

namespace Quant\Spread;

use Closure;
use Quant\Market\Candle;

/**
 * Watches the same symbol on two exchanges and says, minute by minute, how far apart they are.
 *
 * Fed every candle update from both streams (`onCandle()`, forming ones included). The forming
 * candles give the live gap and the widest gap of the minute; when both exchanges have closed
 * the same minute, one `Sample` goes to the listener. A minute one side never closes (a stream
 * that dropped) is let go once a later minute closes on both: a gap measured against a stale
 * price would be a gap that was not there.
 *
 * Pure: no sockets, no clock. The host feeds it and keeps what it emits.
 */
final class Observer
{
    /** @var array<int, array{a?: float, b?: float, closedA?: bool, closedB?: bool, max: float}> by open time */
    private array $minutes = [];

    /** @var array{a?: float, b?: float} the latest price seen on each side, forming candles included */
    private array $latest = [];

    /** @var list<Closure(Sample): void> */
    private array $listeners = [];

    private int $emitted = 0;

    public function __construct(
        public readonly string $exchangeA,
        public readonly string $exchangeB,
        public readonly string $symbol,
        private readonly int $keepMinutes = 10,
    ) {
        if ($exchangeA === $exchangeB) {
            throw new \InvalidArgumentException('A spread is between two different exchanges.');
        }
    }

    /** @param Closure(Sample): void $listener */
    public function onSample(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /** A candle update from `$exchange` — either side, forming or closed. */
    public function onCandle(string $exchange, Candle $candle): void
    {
        $side = match ($exchange) {
            $this->exchangeA => 'a',
            $this->exchangeB => 'b',
            default => throw new \InvalidArgumentException("The spread is {$this->exchangeA}/{$this->exchangeB}, not {$exchange}."),
        };

        $this->latest[$side] = $candle->close;
        $minute = &$this->minutes[$candle->openTime];
        $minute ??= ['max' => 0.0];
        $minute[$side] = $candle->close;
        $minute['closed' . strtoupper($side)] = $candle->closed;

        if (isset($this->latest['a'], $this->latest['b'])) {
            $minute['max'] = max($minute['max'], abs(Sample::bps($this->latest['a'], $this->latest['b'])));
        }

        if (($minute['closedA'] ?? false) && ($minute['closedB'] ?? false)) {
            $sample = new Sample($candle->openTime, $minute['a'], $minute['b'], Sample::bps($minute['a'], $minute['b']), $minute['max']);
            unset($this->minutes[$candle->openTime]);
            $this->emitted++;

            foreach ($this->listeners as $listener) {
                $listener($sample);
            }

            // Minutes older than this one that never closed on both sides are not coming back.
            foreach (array_keys($this->minutes) as $time) {
                if ($time < $candle->openTime) {
                    unset($this->minutes[$time]);
                }
            }
        }

        unset($minute);

        while (count($this->minutes) > $this->keepMinutes) {
            unset($this->minutes[array_key_first($this->minutes)]);
        }
    }

    /** The gap right now from the latest prices on both sides, in bps; null until both have spoken. */
    public function liveBps(): ?float
    {
        return isset($this->latest['a'], $this->latest['b']) ? Sample::bps($this->latest['a'], $this->latest['b']) : null;
    }

    /** @return array{a?: float, b?: float} */
    public function latest(): array
    {
        return $this->latest;
    }

    public function emitted(): int
    {
        return $this->emitted;
    }
}
