<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\DivisionByZeroException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Services\ArithmeticEvaluator;

describe('ArithmeticEvaluator', function () {
    beforeEach(function () {
        $this->evaluator = new ArithmeticEvaluator();
    });

    it('applyOperator() adds two unitless numbers', function () {
        $left  = new NumberNode(10.0, null, false);
        $right = new NumberNode(5.0, null, false);

        $result = $this->evaluator->applyOperator($left, '+', $right);

        expect($result->value)->toBe(15.0)
            ->and($result->unit)->toBeNull();
    });

    it('applyOperator() subtracts two unitless numbers', function () {
        $left  = new NumberNode(10.0, null, false);
        $right = new NumberNode(3.0, null, false);

        $result = $this->evaluator->applyOperator($left, '-', $right);

        expect($result->value)->toBe(7.0);
    });

    it('applyOperator() multiplies two numbers', function () {
        $left  = new NumberNode(4.0, null, false);
        $right = new NumberNode(3.0, null, false);

        $result = $this->evaluator->applyOperator($left, '*', $right);

        expect($result->value)->toBe(12.0);
    });

    it('applyOperator() divides two numbers', function () {
        $left  = new NumberNode(10.0, null, false);
        $right = new NumberNode(2.0, null, false);

        $result = $this->evaluator->applyOperator($left, '/', $right);

        expect($result->value)->toBe(5.0);
    });

    it('applyOperator() computes modulo', function () {
        $left  = new NumberNode(10.0, null, false);
        $right = new NumberNode(3.0, null, false);

        $result = $this->evaluator->applyOperator($left, '%', $right);

        expect((float) $result->value)->toBeCloseTo(1.0);
    });

    it('applyOperator() throws DivisionByZeroException', function () {
        $left  = new NumberNode(5.0, null, false);
        $right = new NumberNode(0.0, null, false);

        expect(fn() => $this->evaluator->applyOperator($left, '/', $right))
            ->toThrow(DivisionByZeroException::class);
    });

    it('applyOperator() throws DivisionByZeroException for modulo by zero', function () {
        $left  = new NumberNode(5.0, null, false);
        $right = new NumberNode(0.0, null, false);

        expect(fn() => $this->evaluator->applyOperator($left, '%', $right))
            ->toThrow(DivisionByZeroException::class);
    });

    it('applyOperator() preserves left unit on addition', function () {
        $left  = new NumberNode(10.0, 'px', false);
        $right = new NumberNode(5.0, 'px', false);

        $result = $this->evaluator->applyOperator($left, '+', $right);

        expect($result->unit)->toBe('px');
    });

    it('applyOperator() throws IncompatibleUnitsException for incompatible units', function () {
        $left  = new NumberNode(10.0, 'px', false);
        $right = new NumberNode(5.0, 'em', false);

        expect(fn() => $this->evaluator->applyOperator($left, '+', $right))
            ->toThrow(IncompatibleUnitsException::class);
    });

    it('applyOperator() throws IncompatibleUnitsException for modulo with incompatible units', function () {
        $left  = new NumberNode(10.0, 'px', false);
        $right = new NumberNode(5.0, 'em', false);

        expect(fn() => $this->evaluator->applyOperator($left, '%', $right))
            ->toThrow(IncompatibleUnitsException::class);
    });

    it('evaluate() returns null for non-space-separated list', function () {
        $list = new ListNode(
            [new NumberNode(1.0), new NumberNode(2.0)],
            'comma',
        );

        expect($this->evaluator->evaluate($list, true))->toBeNull();
    });

    it('evaluate() collapses unary minus', function () {
        // [-] [5] — unary minus applied to 5
        $list = new ListNode(
            [new StringNode('-'), new NumberNode(5.0, null, false)],
            'space',
        );

        $result = $this->evaluator->evaluate($list, false);

        expect($result)->toBeInstanceOf(NumberNode::class);

        if (! $result instanceof NumberNode) {
            throw new RuntimeException('Expected NumberNode result.');
        }

        expect((float) $result->value)->toBe(-5.0);
    });

    it('evaluate() collapses unary plus', function () {
        $list = new ListNode(
            [new StringNode('+'), new NumberNode(5.0, null, false)],
            'space',
        );

        $result = $this->evaluator->evaluate($list, false);

        expect($result)->toBeInstanceOf(NumberNode::class);

        if (! $result instanceof NumberNode) {
            throw new RuntimeException('Expected NumberNode result.');
        }

        expect((float) $result->value)->toBe(5.0);
    });

    it('evaluate() computes simple arithmetic expression', function () {
        // 3 + 4 → 7
        $list = new ListNode(
            [
                new NumberNode(3.0, null, false),
                new StringNode('+'),
                new NumberNode(4.0, null, false),
            ],
            'space',
        );

        $result = $this->evaluator->evaluate($list, false);

        expect($result)->toBeInstanceOf(NumberNode::class);

        if (! $result instanceof NumberNode) {
            throw new RuntimeException('Expected NumberNode result.');
        }

        expect((float) $result->value)->toBe(7.0);
    });

    it('evaluate() returns null for strict lists with unsupported operators', function () {
        $list = new ListNode(
            [
                new NumberNode(3.0, null, false),
                new StringNode('^'),
                new NumberNode(4.0, null, false),
            ],
            'space',
        );

        expect($this->evaluator->evaluate($list, false))->toBeNull();
    });
});
