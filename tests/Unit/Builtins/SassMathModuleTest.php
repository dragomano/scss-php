<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassMathModule;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\NumberNode;

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

    it('evaluates ceil', function () {
        $result = $this->module->call('ceil', [new NumberNode(1.2, 'px')], []);
        expect($result->value)->toBe(2)->and($result->unit)->toBe('px');
    });

    it('evaluates clamp', function () {
        $result = $this->module->call('clamp', [new NumberNode(1), new NumberNode(10), new NumberNode(5)], []);
        expect($result->value)->toBe(5);
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

    it('evaluates floor', function () {
        $result = $this->module->call('floor', [new NumberNode(1.8, 'px')], []);
        expect($result->value)->toBe(1)->and($result->unit)->toBe('px');
    });

    it('evaluates hypot', function () {
        $result = $this->module->call('hypot', [new NumberNode(3), new NumberNode(4)], []);
        expect($result->value)->toBeCloseTo(5.0, 0.001);
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

    it('evaluates max', function () {
        $result = $this->module->call('max', [new NumberNode(1), new NumberNode(5), new NumberNode(2)], []);
        expect($result->value)->toBe(5);
    });

    it('evaluates min', function () {
        $result = $this->module->call('min', [new NumberNode(1), new NumberNode(5), new NumberNode(2)], []);
        expect($result->value)->toBe(1);
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

    it('evaluates round', function () {
        $result = $this->module->call('round', [new NumberNode(1.8, 'px')], []);
        expect($result->value)->toBe(2)->and($result->unit)->toBe('px');
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
});
