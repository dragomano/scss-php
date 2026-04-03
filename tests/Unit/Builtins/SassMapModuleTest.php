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
use Bugo\SCSS\Runtime\BuiltinCallContext;
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

    it('returns null when get() misses an intermediate nested key', function () {
        $result = $this->module->call('get', [$this->map, new StringNode('missing'), new StringNode('x')], []);

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

    it('returns the original map when deep-remove receives an empty path', function () {
        $result = $this->accessor->callMethod('removeNested', [$this->map, []]);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result)->toEqual($this->map);
    });

    it('returns the original map when deep-remove cannot descend into a scalar or missing path', function () {
        $scalarPathResult = $this->module->call('deep-remove', [$this->map, new StringNode('a'), new StringNode('x')], []);
        $missingPathResult = $this->module->call('deep-remove', [$this->map, new StringNode('missing')], []);

        expect($scalarPathResult)->toBeInstanceOf(MapNode::class)
            ->and($scalarPathResult)->toEqual($this->map)
            ->and($missingPathResult)->toBeInstanceOf(MapNode::class)
            ->and($missingPathResult)->toEqual($this->map);
    });

    it('returns the original map when modifyNested is called with an empty path and non-map result', function () {
        $result = $this->accessor->callMethod('modifyNested', [
            $this->map,
            [],
            static fn(MapNode $map): NumberNode => new NumberNode(99),
            true,
        ]);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result)->toEqual($this->map);
    });

    it('preserves the map when modifyNested misses a path and nesting is disabled', function () {
        $result = $this->accessor->callMethod('modifyNested', [
            $this->map,
            [new StringNode('missing'), new StringNode('leaf')],
            static fn(AstNode $existing): NumberNode => new NumberNode(10),
            false,
        ]);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result)->toEqual($this->map);
    });

    it('preserves the map when modifyNested hits a scalar before the tail and nesting is disabled', function () {
        $result = $this->accessor->callMethod('modifyNested', [
            $this->map,
            [new StringNode('a'), new StringNode('leaf')],
            static fn(AstNode $existing): NumberNode => new NumberNode(10),
            false,
        ]);

        expect($result)->toBeInstanceOf(MapNode::class)
            ->and($result)->toEqual($this->map);
    });

    it('creates nested maps when set() descends through a scalar or missing key', function () {
        $throughScalar = $this->module->call('set', [
            $this->map,
            new StringNode('a'),
            new StringNode('x'),
            new NumberNode(5),
        ], []);

        $throughMissing = $this->module->call('set', [
            $this->map,
            new StringNode('new'),
            new StringNode('leaf'),
            new NumberNode(8),
        ], []);

        expect($throughScalar)->toBeInstanceOf(MapNode::class)
            ->and($throughScalar->pairs[0]['value'])->toBeInstanceOf(MapNode::class)
            ->and($throughScalar->pairs[0]['value']->pairs[0]['key']->value)->toBe('x')
            ->and($throughScalar->pairs[0]['value']->pairs[0]['value']->value)->toBe(5)
            ->and($throughMissing)->toBeInstanceOf(MapNode::class)
            ->and($throughMissing->pairs[2]['key']->value)->toBe('new')
            ->and($throughMissing->pairs[2]['value'])->toBeInstanceOf(MapNode::class)
            ->and($throughMissing->pairs[2]['value']->pairs[0]['key']->value)->toBe('leaf')
            ->and($throughMissing->pairs[2]['value']->pairs[0]['value']->value)->toBe(8);
    });

    it('uses raw arguments in global alias deprecation suggestions', function () {
        $warnings = [];
        $context = new BuiltinCallContext(
            logWarning: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
            builtinDisplayName: 'map-get',
            rawArguments: [new StringNode('raw-map'), new StringNode('raw-key')],
        );

        $this->module->call('get', [$this->map, new StringNode('a')], [], $context);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('map-get() is deprecated')
            ->and($warnings[0])->toContain('map.get(raw-map, raw-key)');
    });
});
