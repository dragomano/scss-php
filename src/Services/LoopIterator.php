<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\MaxIterationsExceededException;

final readonly class LoopIterator
{
    public const MAX_ITERATIONS = 10000;

    /**
     * @param callable(int $i): bool $body Return false to stop iterating.
     */
    public function forLoop(
        int $from,
        int $to,
        bool $inclusive,
        callable $body,
    ): void {
        $step = $from <= $to ? 1 : -1;

        if (! $inclusive) {
            $to -= $step;
        }

        $iterations = 0;

        for ($i = $from; $step > 0 ? $i <= $to : $i >= $to; $i += $step) {
            if (++$iterations > self::MAX_ITERATIONS) {
                throw new MaxIterationsExceededException('@for');
            }

            if ($body($i) === false) {
                break;
            }
        }
    }

    /**
     * @param callable(): bool $condition
     * @param callable(): bool $body Return false to stop iterating.
     */
    public function whileLoop(
        callable $condition,
        callable $body,
    ): void {
        $iterations = 0;

        while ($condition()) {
            if (++$iterations > self::MAX_ITERATIONS) {
                throw new MaxIterationsExceededException('@while');
            }

            if ($body() === false) {
                break;
            }
        }
    }
}
