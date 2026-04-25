<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\EachLoopBinder;

describe('EachLoopBinder', function () {
    beforeEach(function () {
        $this->binder = new EachLoopBinder((new CompilerContext())->valueFactory);
    });

    it('returns iterable items for lists argument lists maps and scalars', function () {
        $scalar    = new StringNode('single');
        $listItems = [new StringNode('a'), new StringNode('b')];
        $mapItems  = $this->binder->items(new MapNode([
            new MapPair(new StringNode('key'), new NumberNode(1)),
        ]));

        expect($this->binder->items(new ListNode($listItems)))->toBe($listItems)
            ->and($this->binder->items(new ArgumentListNode($listItems)))->toBe($listItems)
            ->and($this->binder->items($scalar))->toBe([$scalar])
            ->and($mapItems)->toHaveCount(1)
            ->and($mapItems[0])->toBeInstanceOf(ListNode::class);
    });

    it('assigns each variables and fills missing values with null', function () {
        $env = new Environment();

        $this->binder->assign(['item'], new StringNode('value'), $env);
        $this->binder->assign(
            ['first', 'second', 'third'],
            new ListNode([new NumberNode(1), new NumberNode(2)]),
            $env,
        );

        expect($env->getCurrentScope()->getVariable('item'))->toBeInstanceOf(StringNode::class)
            ->and($env->getCurrentScope()->getVariable('first'))->toBeInstanceOf(NumberNode::class)
            ->and($env->getCurrentScope()->getVariable('second'))->toBeInstanceOf(NumberNode::class)
            ->and($env->getCurrentScope()->getVariable('third'))->toBeInstanceOf(NullNode::class);
    });
});
