<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Values\AstValueDescriber;

describe('AstValueDescriber', function () {
    it('describes scalar values', function () {
        expect(AstValueDescriber::describe(new VariableReferenceNode('tone')))->toBe('$tone')
            ->and(AstValueDescriber::describe(new NumberNode(12, 'px')))->toBe('12px')
            ->and(AstValueDescriber::describe(new StringNode('quoted', true)))->toBe('"quoted"')
            ->and(AstValueDescriber::describe(new ColorNode('#112233')))->toBe('#112233');
    });

    it('describes nested list and map values', function () {
        $value = new MapNode([
            new MapPair(
                new StringNode('palette'),
                new ListNode([
                    new StringNode('red'),
                    new StringNode('blue'),
                ], 'comma', true),
            ),
        ]);

        expect(AstValueDescriber::describe($value))->toBe('(palette: [red, blue])');
    });
});
