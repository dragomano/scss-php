<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\DivisionByZeroException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Services\ArithmeticEvaluator;

describe('ArithmeticEvaluator', function () {
    it('applyOperator() adds two unitless numbers', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(10.0, null, false);
        $right = new NumberNode(5.0, null, false);

        $result = $evaluator->applyOperator($left, '+', $right);

        expect($result->value)->toBe(15.0)
            ->and($result->unit)->toBeNull();
    });

    it('applyOperator() subtracts two unitless numbers', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(10.0, null, false);
        $right = new NumberNode(3.0, null, false);

        $result = $evaluator->applyOperator($left, '-', $right);

        expect($result->value)->toBe(7.0);
    });

    it('applyOperator() multiplies two numbers', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(4.0, null, false);
        $right = new NumberNode(3.0, null, false);

        $result = $evaluator->applyOperator($left, '*', $right);

        expect($result->value)->toBe(12.0);
    });

    it('applyOperator() divides two numbers', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(10.0, null, false);
        $right = new NumberNode(2.0, null, false);

        $result = $evaluator->applyOperator($left, '/', $right);

        expect($result->value)->toBe(5.0);
    });

    it('applyOperator() computes modulo', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(10.0, null, false);
        $right = new NumberNode(3.0, null, false);

        $result = $evaluator->applyOperator($left, '%', $right);

        expect((float) $result->value)->toBeCloseTo(1.0);
    });

    it('applyOperator() throws DivisionByZeroException', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(5.0, null, false);
        $right = new NumberNode(0.0, null, false);

        expect(fn() => $evaluator->applyOperator($left, '/', $right))
            ->toThrow(DivisionByZeroException::class);
    });

    it('applyOperator() throws DivisionByZeroException for modulo by zero', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(5.0, null, false);
        $right = new NumberNode(0.0, null, false);

        expect(fn() => $evaluator->applyOperator($left, '%', $right))
            ->toThrow(DivisionByZeroException::class);
    });

    it('applyOperator() preserves left unit on addition', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(10.0, 'px', false);
        $right = new NumberNode(5.0, 'px', false);

        $result = $evaluator->applyOperator($left, '+', $right);

        expect($result->unit)->toBe('px');
    });

    it('applyOperator() throws IncompatibleUnitsException for incompatible units', function () {
        $evaluator = new ArithmeticEvaluator();
        $left = new NumberNode(10.0, 'px', false);
        $right = new NumberNode(5.0, 'em', false);

        expect(fn() => $evaluator->applyOperator($left, '+', $right))
            ->toThrow(IncompatibleUnitsException::class);
    });

    it('evaluate() returns null for non-space-separated list', function () {
        $evaluator = new ArithmeticEvaluator();
        $list = new ListNode(
            [new NumberNode(1.0), new NumberNode(2.0)],
            'comma'
        );

        expect($evaluator->evaluate($list, true))->toBeNull();
    });

    it('evaluate() collapses unary minus', function () {
        $evaluator = new ArithmeticEvaluator();
        // [-] [5] — unary minus applied to 5
        $list = new ListNode(
            [new StringNode('-'), new NumberNode(5.0, null, false)],
            'space'
        );

        $result = $evaluator->evaluate($list, false);

        expect($result)->toBeInstanceOf(NumberNode::class);

        if (! $result instanceof NumberNode) {
            throw new RuntimeException('Expected NumberNode result.');
        }

        expect((float) $result->value)->toBe(-5.0);
    });

    it('evaluate() collapses unary plus', function () {
        $evaluator = new ArithmeticEvaluator();
        $list = new ListNode(
            [new StringNode('+'), new NumberNode(5.0, null, false)],
            'space'
        );

        $result = $evaluator->evaluate($list, false);

        expect($result)->toBeInstanceOf(NumberNode::class);

        if (! $result instanceof NumberNode) {
            throw new RuntimeException('Expected NumberNode result.');
        }

        expect((float) $result->value)->toBe(5.0);
    });

    it('evaluate() computes simple arithmetic expression', function () {
        $evaluator = new ArithmeticEvaluator();
        // 3 + 4 → 7
        $list = new ListNode(
            [
                new NumberNode(3.0, null, false),
                new StringNode('+'),
                new NumberNode(4.0, null, false),
            ],
            'space'
        );

        $result = $evaluator->evaluate($list, false);

        expect($result)->toBeInstanceOf(NumberNode::class);

        if (! $result instanceof NumberNode) {
            throw new RuntimeException('Expected NumberNode result.');
        }

        expect((float) $result->value)->toBe(7.0);
    });
});
