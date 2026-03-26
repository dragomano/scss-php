<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassListModule;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('SassListModule', function () {
    beforeEach(function () {
        $this->module = new SassListModule();
        $this->list = new ListNode([new StringNode('a'), new StringNode('b'), new StringNode('c')]);
    });

    it('exposes metadata', function () {
        expect($this->module->getName())->toBe('list')
            ->and($this->module->getFunctions())->toBe([
                'append',
                'index',
                'is-bracketed',
                'join',
                'length',
                'nth',
                'separator',
                'set-nth',
                'slash',
                'zip',
            ])
            ->and($this->module->getGlobalAliases())->toHaveKeys([
                'length', 'nth', 'set-nth', 'join', 'append', 'zip', 'index',
                'list-separator', 'is-bracketed', 'list-slash',
            ]);
    });

    it('evaluates append', function () {
        $result = $this->module->call('append', [$this->list, new StringNode('d'), new StringNode('comma')], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('comma')
            ->and(count($result->items))->toBe(4);
    });

    it('keeps appended list nested', function () {
        $result = $this->module->call('append', [
            new ListNode([new NumberNode(10, 'px'), new NumberNode(20, 'px')], 'space'),
            new ListNode([new NumberNode(30, 'px'), new NumberNode(40, 'px')], 'space'),
        ], []);

        expect($result)->toBeInstanceOf(ListNode::class)
            ->and(count($result->items))->toBe(3)
            ->and($result->items[2])->toBeInstanceOf(ListNode::class)
            ->and($result->items[2]->items[0]->value)->toBe(30)
            ->and($result->items[2]->items[1]->value)->toBe(40);
    });

    it('evaluates index', function () {
        $result = $this->module->call('index', [$this->list, new StringNode('b')], []);
        expect($result->value)->toBe(2);
    });

    it('evaluates is-bracketed', function () {
        $result = $this->module->call('is-bracketed', [new ListNode([new NumberNode(1)], 'space', true)], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)
            ->and($result->value)->toBeTrue();
    });

    it('evaluates join', function () {
        $first = new ListNode([new NumberNode(1), new NumberNode(2)], 'space', true);
        $second = new ListNode([new NumberNode(3)], 'comma');

        $result = $this->module->call('join', [$first, $second, new StringNode('slash'), new StringNode('auto')], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('slash')
            ->and($result->bracketed)->toBeTrue();
    });

    it('evaluates length', function () {
        $result = $this->module->call('length', [$this->list], []);
        expect($result->value)->toBe(3);
    });

    it('counts map pairs for length', function () {
        $result = $this->module->call('length', [
            new MapNode([
                ['key' => new StringNode('width'), 'value' => new NumberNode(10, 'px')],
                ['key' => new StringNode('height'), 'value' => new NumberNode(20, 'px')],
            ]),
        ], []);

        expect($result->value)->toBe(2);
    });

    it('evaluates nth', function () {
        $result = $this->module->call('nth', [$this->list, new NumberNode(2)], []);
        expect($result->value)->toBe('b');
    });

    it('accepts near-integer index for nth', function () {
        $result = $this->module->call('nth', [$this->list, new NumberNode(2.00000000009)], []);
        expect($result->value)->toBe('b');
    });

    it('evaluates separator', function () {
        $result = $this->module->call('separator', [new ListNode([new NumberNode(1), new NumberNode(2)], 'slash')], []);
        expect($result->value)->toBe('slash');
    });

    it('returns space for separator of empty list', function () {
        $result = $this->module->call('separator', [new ListNode([], 'comma')], []);
        expect($result->value)->toBe('space');
    });

    it('evaluates set-nth', function () {
        $result = $this->module->call('set-nth', [$this->list, new NumberNode(2), new StringNode('x')], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->items[1]->value)->toBe('x');
    });

    it('evaluates slash', function () {
        $result = $this->module->call('slash', [new NumberNode(10, 'px'), new NumberNode(12, 'px')], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('slash')
            ->and(count($result->items))->toBe(2);
    });

    it('evaluates zip', function () {
        $first = new ListNode([new NumberNode(1), new NumberNode(2)]);
        $second = new ListNode([new NumberNode(10), new NumberNode(20)]);

        $result = $this->module->call('zip', [$first, $second], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('comma')
            ->and(count($result->items))->toBe(2);
    });
});
