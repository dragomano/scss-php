<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Utils\AstValueComparator;

describe('AstValueComparator', function () {
    describe('equals()', function () {
        it('returns false for nodes of different types', function () {
            expect(AstValueComparator::equals(new StringNode('1'), new NumberNode(1)))->toBeFalse();
        });

        it('compares equal booleans', function () {
            expect(AstValueComparator::equals(new BooleanNode(true), new BooleanNode(true)))->toBeTrue();
        });

        it('compares unequal booleans', function () {
            expect(AstValueComparator::equals(new BooleanNode(true), new BooleanNode(false)))->toBeFalse();
        });

        it('considers two NullNodes equal', function () {
            expect(AstValueComparator::equals(new NullNode(), new NullNode()))->toBeTrue();
        });

        it('compares equal numbers with same unit', function () {
            expect(AstValueComparator::equals(new NumberNode(10, 'px'), new NumberNode(10, 'px')))->toBeTrue();
        });

        it('compares numbers with different units as unequal', function () {
            expect(AstValueComparator::equals(new NumberNode(10, 'px'), new NumberNode(10, 'em')))->toBeFalse();
        });

        it('compares numbers with different values as unequal', function () {
            expect(AstValueComparator::equals(new NumberNode(5, 'px'), new NumberNode(10, 'px')))->toBeFalse();
        });

        it('compares equal strings', function () {
            expect(AstValueComparator::equals(new StringNode('red'), new StringNode('red')))->toBeTrue();
        });

        it('compares unequal strings', function () {
            expect(AstValueComparator::equals(new StringNode('red'), new StringNode('blue')))->toBeFalse();
        });

        it('compares equal colors', function () {
            expect(AstValueComparator::equals(new ColorNode('#f00'), new ColorNode('#f00')))->toBeTrue();
        });

        it('compares unequal colors', function () {
            expect(AstValueComparator::equals(new ColorNode('#f00'), new ColorNode('#00f')))->toBeFalse();
        });

        it('compares equal function nodes', function () {
            $a = new FunctionNode('rgb', [new NumberNode(255), new NumberNode(0), new NumberNode(0)]);
            $b = new FunctionNode('rgb', [new NumberNode(255), new NumberNode(0), new NumberNode(0)]);

            expect(AstValueComparator::equals($a, $b))->toBeTrue();
        });

        it('compares function nodes with different names as unequal', function () {
            $a = new FunctionNode('rgb', []);
            $b = new FunctionNode('hsl', []);

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares function nodes with different argument counts as unequal', function () {
            $a = new FunctionNode('fn', [new NumberNode(1)]);
            $b = new FunctionNode('fn', [new NumberNode(1), new NumberNode(2)]);

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares function nodes with different argument values as unequal', function () {
            $a = new FunctionNode('rgb', [new NumberNode(255), new NumberNode(0), new NumberNode(0)]);
            $b = new FunctionNode('rgb', [new NumberNode(255), new NumberNode(1), new NumberNode(0)]);

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares equal lists', function () {
            $a = new ListNode([new StringNode('a'), new StringNode('b')], 'space');
            $b = new ListNode([new StringNode('a'), new StringNode('b')], 'space');

            expect(AstValueComparator::equals($a, $b))->toBeTrue();
        });

        it('compares lists with different items as unequal', function () {
            $a = new ListNode([new StringNode('a'), new StringNode('b')], 'space');
            $b = new ListNode([new StringNode('a'), new StringNode('c')], 'space');

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares lists with different separators as unequal', function () {
            $a = new ListNode([new StringNode('a')], 'space');
            $b = new ListNode([new StringNode('a')], 'comma');

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares lists with different bracketed flag as unequal', function () {
            $a = new ListNode([new StringNode('a')], 'space', bracketed: false);
            $b = new ListNode([new StringNode('a')], 'space', bracketed: true);

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares equal maps', function () {
            $a = new MapNode([['key' => new StringNode('k'), 'value' => new StringNode('v')]]);
            $b = new MapNode([['key' => new StringNode('k'), 'value' => new StringNode('v')]]);

            expect(AstValueComparator::equals($a, $b))->toBeTrue();
        });

        it('compares maps with different pair counts as unequal', function () {
            $a = new MapNode([['key' => new StringNode('k'), 'value' => new StringNode('v')]]);
            $b = new MapNode([]);

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares maps with different keys as unequal', function () {
            $a = new MapNode([['key' => new StringNode('k1'), 'value' => new StringNode('v')]]);
            $b = new MapNode([['key' => new StringNode('k2'), 'value' => new StringNode('v')]]);

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('compares maps with different values as unequal', function () {
            $a = new MapNode([['key' => new StringNode('k'), 'value' => new StringNode('v1')]]);
            $b = new MapNode([['key' => new StringNode('k'), 'value' => new StringNode('v2')]]);

            expect(AstValueComparator::equals($a, $b))->toBeFalse();
        });

        it('returns false for unknown node types', function () {
            $node = new class extends AstNode {};

            expect(AstValueComparator::equals($node, $node))->toBeFalse();
        });
    });
});
