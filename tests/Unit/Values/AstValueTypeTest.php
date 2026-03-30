<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\AstValueType;

describe('AstValueType', function () {
    it('classifies Sass value nodes', function () {
        expect(AstValueType::fromNode(new NumberNode(1))->value)->toBe('number')
            ->and(AstValueType::fromNode(new ColorNode('#abc'))->value)->toBe('color')
            ->and(AstValueType::fromNode(new ArgumentListNode())->value)->toBe('arglist')
            ->and(AstValueType::fromNode(new ListNode([]))->value)->toBe('list')
            ->and(AstValueType::fromNode(new MapNode([]))->value)->toBe('map')
            ->and(AstValueType::fromNode(new FunctionNode('calc'))->value)->toBe('calculation')
            ->and(AstValueType::fromNode(new FunctionNode('fn'))->value)->toBe('function')
            ->and(AstValueType::fromNode(new MixinRefNode('button'))->value)->toBe('mixin')
            ->and(AstValueType::fromNode(new BooleanNode(true))->value)->toBe('bool')
            ->and(AstValueType::fromNode(new NullNode())->value)->toBe('null')
            ->and(AstValueType::fromNode(new StringNode('abc'))->value)->toBe('string');
    });

    it('falls back to string for unsupported nodes', function () {
        expect(AstValueType::fromNode(new class () extends AstNode {})->value)->toBe('string');
    });
});
