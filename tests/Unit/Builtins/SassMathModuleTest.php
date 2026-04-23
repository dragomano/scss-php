<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassMathModule;
use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

describe('SassMathModule', function () {
    beforeEach(function () {
        $this->module = new SassMathModule();
    });

    it('exposes metadata', function () {
        expect($this->module->getName())->toBe('math')
            ->and($this->module->getFunctions())->toBe([
                'abs',
                'acos',
                'asin',
                'atan',
                'atan2',
                'ceil',
                'clamp',
                'compatible',
                'cos',
                'div',
                'floor',
                'hypot',
                'is-unitless',
                'log',
                'max',
                'min',
                'percentage',
                'pow',
                'random',
                'round',
                'sin',
                'sqrt',
                'tan',
                'unit',
            ])
            ->and($this->module->getFunctions())->toHaveCount(24)
            ->and($this->module->getVariables())->toHaveKeys([
                'e',
                'pi',
                'epsilon',
                'max-safe-integer',
                'min-safe-integer',
                'max-number',
                'min-number',
            ])
            ->and($this->module->getGlobalAliases())->toHaveKeys([
                'abs', 'ceil', 'floor', 'max', 'min', 'percentage',
                'random', 'round', 'unit', 'unitless', 'comparable',
            ]);
    });

    it('exposes numeric constants as variables', function () {
        $variables = $this->module->getVariables();

        expect($variables['epsilon'])->toBeInstanceOf(NumberNode::class)
            ->and($variables['epsilon']->value)->toBeGreaterThan(0)
            ->and($variables['epsilon']->value)->toBeLessThan(1)
            ->and($variables['max-safe-integer']->value)->toBe(9007199254740991)
            ->and($variables['min-safe-integer']->value)->toBe(-9007199254740991);
    });

    it('evaluates abs', function () {
        $result = $this->module->call('abs', [new NumberNode(-10, 'px')], []);

        expect($result->value)->toBe(10)->and($result->unit)->toBe('px');
    });

    it('evaluates acos', function () {
        $result = $this->module->call('acos', [new NumberNode(0)], []);
        expect($result->unit)->toBe('deg')->and($result->value)->toBeCloseTo(90.0, 0.001);

    });

    it('evaluates asin', function () {
        $result = $this->module->call('asin', [new NumberNode(1)], []);

        expect($result->value)->toBeCloseTo(90.0, 0.001);
    });

    it('evaluates atan', function () {
        $result = $this->module->call('atan', [new NumberNode(1)], []);

        expect($result->value)->toBeCloseTo(45.0, 0.001);
    });

    it('evaluates atan2', function () {
        $result = $this->module->call('atan2', [new NumberNode(1), new NumberNode(1)], []);

        expect($result->value)->toBeCloseTo(45.0, 0.001);
    });

    it('throws for atan2 with incompatible units', function () {
        expect(fn() => $this->module->call('atan2', [new NumberNode(1, 'px'), new NumberNode(1, 's')], []))
            ->toThrow(IncompatibleUnitsException::class);
    });

    it('evaluates ceil', function () {
        $result = $this->module->call('ceil', [new NumberNode(1.2, 'px')], []);

        expect($result->value)->toBe(2)->and($result->unit)->toBe('px');
    });

    it('evaluates clamp', function () {
        $result = $this->module->call('clamp', [new NumberNode(1), new NumberNode(10), new NumberNode(5)], []);

        expect($result->value)->toBe(5);
    });

    it('returns min and middle values for clamp and rethrows non-css errors', function () {
        $min    = $this->module->call('clamp', [new NumberNode(10), new NumberNode(5), new NumberNode(20)], []);
        $middle = $this->module->call('clamp', [new NumberNode(1), new NumberNode(3), new NumberNode(5)], []);

        expect($min->value)->toBe(10)
            ->and($middle->value)->toBe(3)
            ->and(fn() => $this->module->call('clamp', [new NumberNode(1)], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates compatible', function () {
        $result = $this->module->call('compatible', [new NumberNode(1, 'px'), new NumberNode(2, 'px')], []);

        expect($result)->toBeInstanceOf(BooleanNode::class)
            ->and($result->value)->toBeTrue();
    });

    it('evaluates cos', function () {
        $result = $this->module->call('cos', [new NumberNode(0, 'deg')], []);

        expect($result->value)->toBeCloseTo(1.0, 0.001);
    });

    it('evaluates div', function () {
        $result = $this->module->call('div', [new NumberNode(10, 'px'), new NumberNode(2)], []);

        expect($result->value)->toBe(5.0)->and($result->unit)->toBe('px');
    });

    it('handles division by zero edge cases', function () {
        $nan              = $this->module->call('div', [new NumberNode(0), new NumberNode(0)], []);
        $positiveInfinity = $this->module->call('div', [new NumberNode(10), new NumberNode(0)], []);
        $negativeInfinity = $this->module->call('div', [new NumberNode(-10), new NumberNode(0)], []);

        expect(is_nan($nan->value))->toBeTrue()
            ->and($positiveInfinity->value)->toBe(INF)
            ->and($negativeInfinity->value)->toBe(-INF);
    });

    it('evaluates floor', function () {
        $result = $this->module->call('floor', [new NumberNode(1.8, 'px')], []);

        expect($result->value)->toBe(1)->and($result->unit)->toBe('px');
    });

    it('evaluates hypot', function () {
        $result = $this->module->call('hypot', [new NumberNode(3), new NumberNode(4)], []);

        expect($result->value)->toBeCloseTo(5.0, 0.001);
    });

    it('throws for empty or incompatible hypot inputs', function () {
        expect(fn() => $this->module->call('hypot', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('hypot', [new NumberNode(3, 'px'), new NumberNode(4, 's')], []))
            ->toThrow(IncompatibleUnitsException::class);
    });

    it('evaluates is-unitless', function () {
        $result = $this->module->call('is-unitless', [new NumberNode(3)], []);

        expect($result)->toBeInstanceOf(BooleanNode::class)
            ->and($result->value)->toBeTrue();
    });

    it('evaluates log', function () {
        $result = $this->module->call('log', [new NumberNode(8), new NumberNode(2)], []);

        expect($result->value)->toBeCloseTo(3.0, 0.001);
    });

    it('handles log edge cases', function () {
        $baseZero = $this->module->call('log', [new NumberNode(8), new NumberNode(0)], []);
        $baseOne  = $this->module->call('log', [new NumberNode(8), new NumberNode(1)], []);
        $natural  = $this->module->call('log', [new NumberNode(8)], []);

        expect(is_nan($baseZero->value))->toBeTrue()
            ->and(is_nan($baseOne->value))->toBeTrue()
            ->and($natural->value)->toBeCloseTo(log(8), 0.000001);
    });

    it('evaluates max', function () {
        $result = $this->module->call('max', [new NumberNode(1), new NumberNode(5), new NumberNode(2)], []);

        expect($result->value)->toBe(5);
    });

    it('evaluates min', function () {
        $result = $this->module->call('min', [new NumberNode(1), new NumberNode(5), new NumberNode(2)], []);

        expect($result->value)->toBe(1);
    });

    it('rethrows missing-argument errors for max and missing-or-unitful unitless arguments', function () {
        expect(fn() => $this->module->call('max', [], []))
            ->toThrow(MissingFunctionArgumentsException::class, 'expects at least one number')
            ->and(fn() => $this->module->call('sqrt', [], []))
            ->toThrow(MissingFunctionArgumentsException::class, 'expects required number argument')
            ->and(fn() => $this->module->call('sqrt', [new NumberNode(9, 'px')], []))
            ->toThrow(MissingFunctionArgumentsException::class, 'expects a unitless number');
    });

    it('evaluates percentage', function () {
        $result = $this->module->call('percentage', [new NumberNode(0.25)], []);

        expect($result->value)->toBe(25.0)->and($result->unit)->toBe('%');
    });

    it('evaluates pow', function () {
        $result = $this->module->call('pow', [new NumberNode(2), new NumberNode(3)], []);

        expect($result->value)->toBe(8.0);
    });

    it('evaluates random without limit', function () {
        $result = $this->module->call('random', [], []);

        expect($result->value)->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(1);
    });

    it('evaluates random with integer limit', function () {
        $result = $this->module->call('random', [new NumberNode(3)], []);

        expect($result->value)->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3);
    });

    it('throws for invalid random limit', function () {
        expect(fn() => $this->module->call('random', [new NumberNode(0)], []))
            ->toThrow(BuiltinArgumentException::class)
            ->and(fn() => $this->module->call('random', [new NumberNode(1.5)], []))
            ->toThrow(BuiltinArgumentException::class);
    });

    it('evaluates round', function () {
        $result = $this->module->call('round', [new NumberNode(1.8, 'px')], []);

        expect($result->value)->toBe(2)->and($result->unit)->toBe('px');
    });

    it('rethrows non-css errors for abs and round', function () {
        expect(fn() => $this->module->call('abs', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('round', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates sin', function () {
        $result = $this->module->call('sin', [new NumberNode(90, 'deg')], []);

        expect($result->value)->toBeCloseTo(1.0, 0.001);
    });

    it('evaluates sqrt', function () {
        $result = $this->module->call('sqrt', [new NumberNode(9)], []);

        expect($result->value)->toBe(3.0);
    });

    it('evaluates tan', function () {
        $result = $this->module->call('tan', [new NumberNode(45, 'deg')], []);

        expect($result->value)->toBeCloseTo(1.0, 0.01);
    });

    it('evaluates unit', function () {
        $result = $this->module->call('unit', [new NumberNode(10, 'px')], []);

        expect($result->value)->toBe('px');
    });

    it('uses raw arguments when formatting deprecated global math suggestions', function () {
        $warnings = [];
        $context  = new BuiltinCallContext(
            logWarning: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
            rawArguments: [new StringNode('quoted', true), new BooleanNode(true)],
        );

        $this->module->call('max', [new NumberNode(1), new NumberNode(2)], [], $context);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('max() is deprecated')
            ->and($warnings[0])->toContain('math.max("quoted", true)');
    });

    it('converts unitless turn and grad angles to radians for trig functions', function () {
        $unitless = $this->module->call('cos', [new NumberNode(M_PI)], []);
        $turn     = $this->module->call('sin', [new NumberNode(0.25, 'turn')], []);
        $grad     = $this->module->call('sin', [new NumberNode(100, 'grad')], []);

        expect($unitless->value)->toBeCloseTo(-1.0, 0.001)
            ->and($turn->value)->toBeCloseTo(1.0, 0.001)
            ->and($grad->value)->toBeCloseTo(1.0, 0.001);
    });

    it('throws for unsupported trig units', function () {
        expect(fn() => $this->module->call('tan', [new NumberNode(1, 'px')], []))
            ->toThrow(BuiltinArgumentException::class, 'unitless, deg, rad, grad, or turn');
    });
});
