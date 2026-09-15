<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Services\LoopIterator;

describe('LoopIterator', function () {
    beforeEach(function () {
        $this->iterator = new LoopIterator();
    });

    it('iterates forward for-loop with inclusive range', function () {
        $collected = [];

        $this->iterator->forLoop(1, 3, true, function (int $i) use (&$collected) {
            $collected[] = $i;

            return true;
        });

        expect($collected)->toBe([1, 2, 3]);
    });

    it('iterates forward for-loop with exclusive range', function () {
        $collected = [];

        $this->iterator->forLoop(1, 3, false, function (int $i) use (&$collected) {
            $collected[] = $i;

            return true;
        });

        expect($collected)->toBe([1, 2]);
    });

    it('iterates backward for-loop', function () {
        $collected = [];

        $this->iterator->forLoop(5, 1, true, function (int $i) use (&$collected) {
            $collected[] = $i;

            return true;
        });

        expect($collected)->toBe([5, 4, 3, 2, 1]);
    });

    it('stops for-loop early when callback returns false', function () {
        $collected = [];

        $this->iterator->forLoop(1, 10, true, function (int $i) use (&$collected) {
            $collected[] = $i;

            return $i < 3;
        });

        expect($collected)->toBe([1, 2, 3]);
    });

    it('produces no iterations when from equals to in exclusive range', function () {
        $collected = [];

        $this->iterator->forLoop(1, 1, false, function (int $i) use (&$collected) {
            $collected[] = $i;

            return true;
        });

        expect($collected)->toBeEmpty();
    });

    it('throws after exceeding max iterations in for-loop', function () {
        $this->iterator->forLoop(1, LoopIterator::MAX_ITERATIONS + 1, true, fn(int $i) => true);
    })->throws(MaxIterationsExceededException::class);

    it('evaluates while-loop with condition', function () {
        $counter   = 0;
        $collected = [];

        $this->iterator->whileLoop(
            function () use (&$counter) {
                return $counter < 5;
            },
            function () use (&$counter, &$collected) {
                $counter++;
                $collected[] = $counter;

                return true;
            },
        );

        expect($collected)->toBe([1, 2, 3, 4, 5]);
    });

    it('does not execute while-loop body when condition is initially false', function () {
        $executed = false;

        $this->iterator->whileLoop(
            fn() => false,
            function () use (&$executed) {
                $executed = true;

                return true;
            },
        );

        expect($executed)->toBeFalse();
    });

    it('stops while-loop early when condition becomes false mid-iteration', function () {
        $counter = 0;

        $this->iterator->whileLoop(
            function () use (&$counter) {
                return $counter < 3;
            },
            function () use (&$counter) {
                $counter++;

                return true;
            },
        );

        expect($counter)->toBe(3);
    });

    it('throws after exceeding max iterations in while-loop', function () {
        $this->iterator->whileLoop(
            fn() => true,
            fn() => true,
        );
    })->throws(MaxIterationsExceededException::class);
});
