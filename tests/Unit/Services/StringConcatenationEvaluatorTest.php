<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\ArithmeticEvaluator;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\StringConcatenationEvaluator;

describe('StringConcatenationEvaluator', function () {
    beforeEach(function () {
        $this->evaluator = new StringConcatenationEvaluator(
            new class implements AstValueFormatterInterface {
                public function format(AstNode $node, Environment $env): string
                {
                    return match (true) {
                        $node instanceof NumberNode => "$node->value" . ($node->unit ?? ''),
                        $node instanceof StringNode => $node->value,
                        default                     => '',
                    };
                }
            },
            new ArithmeticEvaluator(),
        );
    });

    describe('evaluate()', function () {
        it('returns null for non-space-separated list', function () {
            $list = new ListNode([new StringNode('a'), new StringNode('b')], 'comma');

            expect($this->evaluator->evaluate($list))->toBeNull();
        });

        it('handles unary minus prefix', function () {
            $list = new ListNode([new StringNode('-'), new StringNode('moz')], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(StringNode::class)
                ->and($result->value)->toBe('-moz');
        });

        it('handles unary slash prefix', function () {
            $list = new ListNode([new StringNode('/'), new StringNode('15px')], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(StringNode::class)
                ->and($result->value)->toBe('/15px');
        });

        it('returns null when the leading unary chain operand is not foldable', function () {
            $list = new ListNode([new StringNode('-'), new StringNode('+'), new StringNode('-')], 'space');

            expect($this->evaluator->evaluate($list))->toBeNull();
        });

        it('folds a leading unary operator chain with a foldable operand', function () {
            $list = new ListNode([new StringNode('-'), new StringNode('+'), new StringNode('moz')], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(StringNode::class)
                ->and($result->value)->toBe('-+moz');
        });

        it('collapses number plus unit suffix into dimension', function () {
            $list = new ListNode([new NumberNode(42), new StringNode('+'), new StringNode('px')], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(NumberNode::class)
                ->and($result->value)->toBe(42)
                ->and($result->unit)->toBe('px');
        });

        it('concatenates unquoted identifier strings with plus operator', function () {
            $list = new ListNode([
                new StringNode('sans'),
                new StringNode('+'),
                new StringNode('serif'),
            ], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(StringNode::class)
                ->and($result->value)->toBe('sansserif')
                ->and($result->quoted)->toBeFalse();
        });

        it('concatenates quoted strings with plus operator', function () {
            $list = new ListNode([
                new StringNode('hello', quoted: true),
                new StringNode('+'),
                new StringNode(' world', quoted: true),
            ], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(StringNode::class)
                ->and($result->value)->toBe('hello world')
                ->and($result->quoted)->toBeTrue();
        });

        it('concatenates with minus operator inserting hyphen', function () {
            $list = new ListNode([
                new StringNode('sans'),
                new StringNode('-'),
                new StringNode('serif'),
            ], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(StringNode::class)
                ->and($result->value)->toBe('sans-serif');
        });

        it('returns null when operand count is even', function () {
            $list = new ListNode([
                new StringNode('a'),
                new StringNode('+'),
                new StringNode('b'),
                new StringNode('+'),
            ], 'space');

            expect($this->evaluator->evaluate($list))->toBeNull();
        });

        it('returns null when operator is not plus or minus', function () {
            $list = new ListNode([
                new StringNode('a'),
                new StringNode('*'),
                new StringNode('b'),
            ], 'space');

            expect($this->evaluator->evaluate($list))->toBeNull();
        });

        it('returns null when no quoted strings and operands are not all strings', function () {
            $list = new ListNode([
                new NumberNode(10, 'px'),
                new StringNode('+'),
                new NumberNode(5, 'px'),
            ], 'space');

            expect($this->evaluator->evaluate($list))->toBeNull();
        });

        it('collapses number plus percent suffix into a dimension', function () {
            $list = new ListNode([new NumberNode(42), new StringNode('+'), new StringNode('%')], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(NumberNode::class)
                ->and($result->value)->toBe(42)
                ->and($result->unit)->toBe('%');
        });

        it('concatenates numbers with non-unit string suffixes like the reference compiler', function () {
            $invalidUnit = new ListNode([new NumberNode(42), new StringNode('+'), new StringNode('p2')], 'space');
            $emptyUnit   = new ListNode([new NumberNode(42), new StringNode('+'), new StringNode('')], 'space');

            $invalidResult = $this->evaluator->evaluate($invalidUnit);
            $emptyResult   = $this->evaluator->evaluate($emptyUnit);

            expect($invalidResult)->toBeInstanceOf(StringNode::class)
                ->and($invalidResult->value)->toBe('42p2')
                ->and($emptyResult)->toBeInstanceOf(StringNode::class)
                ->and($emptyResult->value)->toBe('42');
        });

        it('concatenates numeric-like unquoted string operands like the reference compiler', function () {
            $leadingDigits = new ListNode([new StringNode('12px'), new StringNode('+'), new StringNode('solid')], 'space');
            $leadingDot    = new ListNode([new StringNode('.5rem'), new StringNode('+'), new StringNode('solid')], 'space');
            $signedDot     = new ListNode([new StringNode('-.5rem'), new StringNode('+'), new StringNode('solid')], 'space');

            $digitsResult = $this->evaluator->evaluate($leadingDigits);
            $dotResult    = $this->evaluator->evaluate($leadingDot);
            $signedResult = $this->evaluator->evaluate($signedDot);

            expect($digitsResult)->toBeInstanceOf(StringNode::class)
                ->and($digitsResult->value)->toBe('12pxsolid')
                ->and($dotResult)->toBeInstanceOf(StringNode::class)
                ->and($dotResult->value)->toBe('.5remsolid')
                ->and($signedResult)->toBeInstanceOf(StringNode::class)
                ->and($signedResult->value)->toBe('-.5remsolid');
        });

        it('does not treat empty unquoted strings as numeric-like operands', function () {
            $list = new ListNode([new StringNode(''), new StringNode('+'), new StringNode('solid')], 'space');

            $result = $this->evaluator->evaluate($list);

            expect($result)->toBeInstanceOf(StringNode::class)
                ->and($result->value)->toBe('solid');
        });
    });
});
