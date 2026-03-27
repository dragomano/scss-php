<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassMapModule;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\ReflectionAccessor;

describe('SassMapModule', function () {
    beforeEach(function () {
        $this->module   = new SassMapModule();
        $this->accessor = new ReflectionAccessor($this->module);
        $this->map      = new MapNode([
            ['key' => new StringNode('a'), 'value' => new NumberNode(1)],
            ['key' => new StringNode('nested'), 'value' => new MapNode([
                ['key' => new StringNode('b'), 'value' => new NumberNode(2)],
            ])],
        ]);
    });

    it('exposes metadata', function () {
        expect($this->module->getName())->toBe('map')
            ->and($this->module->getFunctions())->toBe([
                'deep-merge',
                'deep-remove',
                'get',
                'has-key',
                'keys',
                'merge',
                'remove',
                'set',
                'values',
            ])
            ->and($this->module->getGlobalAliases())->toHaveKeys([
                'map-get', 'map-has-key', 'map-keys', 'map-values',
                'map-merge', 'map-remove', 'map-set',
            ]);
    });

    it('evaluates deep-merge', function () {
        $left = new MapNode([
            ['key' => new StringNode('a'), 'value' => new MapNode([
                ['key' => new StringNode('x'), 'value' => new NumberNode(1)],
            ])],
        ]);
        $right = new MapNode([
            ['key' => new StringNode('a'), 'value' => new MapNode([
                ['key' => new StringNode('y'), 'value' => new NumberNode(2)],
            ])],
        ]);

        $result = $this->module->call('deep-merge', [$left, $right], []);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[0]['value'])->toBeInstanceOf(MapNode::class)
            ->and(count($result->pairs[0]['value']->pairs))->toBe(2);
    });

    it('evaluates deep-remove', function () {
        $set    = $this->module->call('set', [$this->map, new StringNode('nested'), new StringNode('x'), new NumberNode(5)], []);
        $result = $this->module->call('deep-remove', [$set, new StringNode('nested'), new StringNode('x')], []);

        expect($result)->toBeInstanceOf(MapNode::class);
    });

    it('evaluates get', function () {
        $result = $this->module->call('get', [$this->map, new StringNode('nested'), new StringNode('b')], []);
        expect($result->value)->toBe(2);
    });

    it('returns null for missing key in get', function () {
        $result = $this->module->call('get', [$this->map, new StringNode('missing')], []);
        expect($result)->toBeInstanceOf(NullNode::class);
    });

    it('evaluates has-key', function () {
        $result = $this->module->call('has-key', [$this->map, new StringNode('nested'), new StringNode('b')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)
            ->and($result->value)->toBeTrue();
    });

    it('returns false for missing key in has-key', function () {
        $result = $this->module->call('has-key', [$this->map, new StringNode('missing')], []);
        expect($result)->toBeInstanceOf(BooleanNode::class)
            ->and($result->value)->toBeFalse();
    });

    it('evaluates keys', function () {
        $result = $this->module->call('keys', [$this->map], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and(count($result->items))->toBe(2);
    });

    it('evaluates merge (two args)', function () {
        $right  = new MapNode([['key' => new StringNode('a'), 'value' => new NumberNode(9)]]);
        $result = $this->module->call('merge', [$this->map, $right], []);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[0]['value']->value)->toBe(9);
    });

    it('evaluates merge (variadic path)', function () {
        $patch = new MapNode([
            ['key' => new StringNode('b'), 'value' => new NumberNode(7)],
            ['key' => new StringNode('c'), 'value' => new NumberNode(8)],
        ]);

        $result = $this->module->call('merge', [$this->map, new StringNode('nested'), $patch], []);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[1]['value'])->toBeInstanceOf(MapNode::class)
            ->and(count($result->pairs[1]['value']->pairs))->toBe(2);
    });

    it('evaluates remove', function () {
        $result = $this->module->call('remove', [$this->map, new StringNode('a')], []);
        expect($result)->toBeInstanceOf(MapNode::class)
            ->and(count($result->pairs))->toBe(1);
    });

    it('evaluates remove without keys', function () {
        $result = $this->module->call('remove', [$this->map], []);
        expect($result)->toBeInstanceOf(MapNode::class)
            ->and(count($result->pairs))->toBe(2);
    });

    it('evaluates set', function () {
        $result = $this->module->call('set', [$this->map, new StringNode('nested'), new StringNode('x'), new NumberNode(5)], []);
        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[1]['value'])->toBeInstanceOf(MapNode::class);
    });

    it('evaluates values', function () {
        $result = $this->module->call('values', [$this->map], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and(count($result->items))->toBe(2);
    });

    it('throws for unknown functions and missing required arguments', function () {
        expect(fn() => $this->module->call('unknown', [], []))
            ->toThrow(UnknownSassFunctionException::class)
            ->and(fn() => $this->module->call('deep-merge', [$this->map], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('deep-remove', [$this->map], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('has-key', [$this->map], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('keys', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('merge', [$this->map], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('remove', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('set', [$this->map, new StringNode('a')], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('values', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('returns null when get() traverses into a non-map value', function () {
        $result = $this->module->call('get', [$this->map, new StringNode('a'), new StringNode('x')], []);

        expect($result)->toBeInstanceOf(NullNode::class);
    });

    it('returns key and value lists preserving map order', function () {
        $keys   = $this->module->call('keys', [$this->map], []);
        $values = $this->module->call('values', [$this->map], []);

        expect($keys)->toBeInstanceOf(ListNode::class)
            ->and($keys->items[0]->value)->toBe('a')
            ->and($keys->items[1]->value)->toBe('nested')
            ->and($values)->toBeInstanceOf(ListNode::class)
            ->and($values->items[0]->value)->toBe(1)
            ->and($values->items[1])->toBeInstanceOf(MapNode::class);
    });

    it('throws when merge second argument is not a map', function () {
        expect(fn() => $this->module->call('merge', [$this->map, new StringNode('nested')], []))
            ->toThrow('merge() (map module) expects map');
    });

    it('replaces scalar path values with the provided map in variadic merge', function () {
        $patch = new MapNode([
            ['key' => new StringNode('x'), 'value' => new NumberNode(9)],
        ]);

        $result = $this->module->call('merge', [$this->map, new StringNode('a'), $patch], []);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[0]['value'])->toBeInstanceOf(MapNode::class)
            ->and($result->pairs[0]['value']->pairs[0]['value']->value)->toBe(9);
    });

    it('covers merge() guard for non-integer last variadic index', function () {
        $patch = new MapNode([
            ['key' => new StringNode('x'), 'value' => new NumberNode(9)],
        ]);

        expect(fn() => $this->accessor->callMethod('merge', [[
            0 => $this->map,
            'path' => new StringNode('nested'),
            'patch' => $patch,
        ], null]))->toThrow(MissingFunctionArgumentsException::class);
    });
});
