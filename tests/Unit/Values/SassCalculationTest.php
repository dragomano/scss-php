<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassCalculation;
use Bugo\SCSS\Values\SassNumber;

describe('SassCalculation', function () {
    describe('isCalculationFunctionName()', function () {
        it('recognises calc', function () {
            expect(SassCalculation::isCalculationFunctionName('calc'))->toBeTrue();
        });

        it('recognises min max clamp', function () {
            expect(SassCalculation::isCalculationFunctionName('min'))->toBeTrue()
                ->and(SassCalculation::isCalculationFunctionName('max'))->toBeTrue()
                ->and(SassCalculation::isCalculationFunctionName('clamp'))->toBeTrue();
        });

        it('recognises trig functions', function () {
            expect(SassCalculation::isCalculationFunctionName('sin'))->toBeTrue()
                ->and(SassCalculation::isCalculationFunctionName('cos'))->toBeTrue()
                ->and(SassCalculation::isCalculationFunctionName('tan'))->toBeTrue();
        });

        it('is case insensitive', function () {
            expect(SassCalculation::isCalculationFunctionName('CALC'))->toBeTrue()
                ->and(SassCalculation::isCalculationFunctionName('Min'))->toBeTrue();
        });

        it('returns false for unknown function names', function () {
            expect(SassCalculation::isCalculationFunctionName('unknown'))->toBeFalse()
                ->and(SassCalculation::isCalculationFunctionName('darken'))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect(SassCalculation::isCalculationFunctionName(''))->toBeFalse();
        });
    });

    describe('toCss()', function () {
        it('renders function name with empty parens for no arguments', function () {
            $calc = new SassCalculation('calc');

            expect($calc->toCss())->toBe('calc()');
        });

        it('renders string arguments joined by comma', function () {
            $calc = new SassCalculation('calc', ['100%', '+', '20px']);

            expect($calc->toCss())->toBe('calc(100%, +, 20px)');
        });

        it('renders SassValue arguments via toCss()', function () {
            $num  = new SassNumber(10, 'px');
            $calc = new SassCalculation('min', [$num, '20px']);

            expect($calc->toCss())->toBe('min(10px, 20px)');
        });

        it('renders modern color function with alpha as slash-separated', function () {
            $calc = new SassCalculation('hwb', ['1', '2', '3', '0.5']);

            expect($calc->toCss())->toBe('hwb(1 2 3 / 0.5)');
        });

        it('renders color() with more than four channels', function () {
            $calc = new SassCalculation('color', ['red', 'green', 'blue', 'alpha', '1']);

            expect($calc->toCss())->toBe('color(red green blue alpha / 1)');
        });
    });

    describe('isTruthy()', function () {
        it('always returns true', function () {
            expect((new SassCalculation('calc'))->isTruthy())->toBeTrue();
        });
    });

    it('__toString() delegates to toCss()', function () {
        $calc = new SassCalculation('calc', ['1px']);

        expect((string) $calc)->toBe('calc(1px)');
    });
});
