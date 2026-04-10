<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\AstValueSuggestionDescriber;

describe('AstValueSuggestionDescriber', function () {
    it('describes suggestion arguments', function () {
        expect(AstValueSuggestionDescriber::describeArguments([
            new NumberNode(1),
            new StringNode('x'),
        ]))->toBe(['1', 'x']);
    });

    it('describes list-like values with suggestion-specific formatting', function () {
        expect(AstValueSuggestionDescriber::describe(new ListNode([], 'space')))->toBe('()')
            ->and(AstValueSuggestionDescriber::describe(new ListNode([
                new NumberNode(1),
                new NumberNode(2),
            ], 'comma')))->toBe('(1, 2)')
            ->and(AstValueSuggestionDescriber::describe(new ListNode([
                new NumberNode(10, 'px'),
                new NumberNode(12, 'px'),
            ], 'slash')))->toBe('(10px / 12px)')
            ->and(AstValueSuggestionDescriber::describe(new ArgumentListNode([
                new StringNode('a'),
                new StringNode('b'),
            ], 'space', true)))->toBe('[a b]');
    });

    it('describes maps and falls back for unsupported nodes', function () {
        $map = new MapNode([
            new MapPair(new StringNode('k'), new NumberNode(1)),
        ]);

        expect(AstValueSuggestionDescriber::describe($map))->toBe('(k: 1)')
            ->and(AstValueSuggestionDescriber::describe(new class extends AstNode {}))->toBe('');
    });
});
