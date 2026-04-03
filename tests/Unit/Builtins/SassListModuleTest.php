<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassListModule;
use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Bugo\SCSS\Exceptions\InvalidArgumentTypeException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownListSeparatorException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\ReflectionAccessor;

describe('SassListModule', function () {
    beforeEach(function () {
        $this->module   = new SassListModule();
        $this->list     = new ListNode([new StringNode('a'), new StringNode('b'), new StringNode('c')]);
        $this->accessor = new ReflectionAccessor($this->module);
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
        $first  = new ListNode([new NumberNode(1), new NumberNode(2)], 'space', true);
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
        $first  = new ListNode([new NumberNode(1), new NumberNode(2)]);
        $second = new ListNode([new NumberNode(10), new NumberNode(20)]);

        $result = $this->module->call('zip', [$first, $second], []);
        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('comma')
            ->and(count($result->items))->toBe(2);
    });

    it('throws for unknown functions and missing required arguments', function () {
        expect(fn() => $this->module->call('unknown', [], []))
            ->toThrow(UnknownSassFunctionException::class)
            ->and(fn() => $this->module->call('append', [new StringNode('a')], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('index', [new StringNode('a')], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('is-bracketed', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('join', [new StringNode('a')], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('length', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('separator', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('set-nth', [$this->list, new NumberNode(1)], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('slash', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('returns null for missing index lookups and empty comma list for zip without arguments', function () {
        $index = $this->module->call('index', [$this->list, new StringNode('missing')], []);
        $zip   = $this->module->call('zip', [], []);

        expect($index)->toBeInstanceOf(NullNode::class)
            ->and($zip)->toBeInstanceOf(ListNode::class)
            ->and($zip->separator)->toBe('comma')
            ->and($zip->items)->toBe([]);
    });

    it('accepts argument lists as list values', function () {
        $argumentList = new ArgumentListNode([new NumberNode(1), new NumberNode(2)], 'slash', true);

        $result = $this->module->call('separator', [$argumentList], []);

        expect($result->value)->toBe('slash');
    });

    it('throws for invalid nth and set-nth indexes', function () {
        expect(fn() => $this->module->call('nth', [$this->list, new StringNode('oops')], []))
            ->toThrow(InvalidArgumentTypeException::class)
            ->and(fn() => $this->module->call('nth', [$this->list, new NumberNode(2.5)], []))
            ->toThrow(InvalidArgumentTypeException::class)
            ->and(fn() => $this->module->call('nth', [new ListNode([]), new NumberNode(1)], []))
            ->toThrow(BuiltinArgumentException::class)
            ->and(fn() => $this->module->call('nth', [$this->list, new NumberNode(0)], []))
            ->toThrow(BuiltinArgumentException::class)
            ->and(fn() => $this->module->call('set-nth', [$this->list, new NumberNode(10), new StringNode('x')], []))
            ->toThrow(BuiltinArgumentException::class);
    });

    it('throws for invalid join and append separator arguments', function () {
        expect(fn() => $this->module->call('append', [$this->list, new StringNode('d')], ['separator' => new NumberNode(1)]))
            ->toThrow(InvalidArgumentTypeException::class)
            ->and(fn() => $this->module->call('join', [$this->list, $this->list], ['separator' => new StringNode('weird')]))
            ->toThrow(UnknownListSeparatorException::class);
    });

    it('covers private suggestion and formatting helpers', function () {
        $appendSuggestion = $this->accessor->callMethod('appendSuggestionArguments', [
            [$this->list, new StringNode('d')],
            ['separator' => new StringNode('comma')],
        ]);
        $joinSuggestion = $this->accessor->callMethod('joinSuggestionArguments', [
            [$this->list, new ListNode([new StringNode('x')])],
            ['bracketed' => new BooleanNode(true)],
        ]);
        $separatorSuggestion = $this->accessor->callMethod('deprecatedListSuggestion', [
            'separator',
            [$this->list],
            [],
        ]);
        $describeArgs = $this->accessor->callMethod('describeArguments', [[new NumberNode(1), new StringNode('x')]]);

        expect($appendSuggestion)->toContain('$separator: comma')
            ->and($joinSuggestion)->toContain('$bracketed: ')
            ->and($separatorSuggestion)->toContain('list.separator(')
            ->and($describeArgs)->toBe(['1', 'x']);
    });

    it('covers private describeValue branches', function () {
        $empty     = $this->accessor->callMethod('describeValue', [null]);
        $quoted    = $this->accessor->callMethod('describeValue', [new StringNode('quoted', true)]);
        $bracketed = $this->accessor->callMethod('describeValue', [new ListNode(
            [new NumberNode(1)],
            'space',
            true,
        )]);
        $emptyList = $this->accessor->callMethod('describeValue', [new ListNode([], 'space')]);
        $commaList = $this->accessor->callMethod('describeValue', [new ListNode(
            [new NumberNode(1), new NumberNode(2)],
            'comma',
        )]);
        $spaceList = $this->accessor->callMethod('describeValue', [new ListNode(
            [new StringNode('a'), new StringNode('b')],
            'space',
        )]);

        $map = $this->accessor->callMethod('describeValue', [new MapNode([
            ['key' => new StringNode('k'), 'value' => new NumberNode(1)],
        ])]);

        $fallback = $this->accessor->callMethod('describeValue', [new class extends AstNode {}]);

        expect($empty)->toBe('')
            ->and($quoted)->toBe('"quoted"')
            ->and($bracketed)->toBe('[1]')
            ->and($emptyList)->toBe('()')
            ->and($commaList)->toBe('(1, 2)')
            ->and($spaceList)->toBe('a b')
            ->and($map)->toBe('(k: 1)')
            ->and($fallback)->toBe('');
    });

    it('covers private integer and index helpers', function () {
        $rounded  = $this->accessor->callMethod('requireInteger', [new NumberNode(2.00000000001), 'list.nth() index']);
        $autoJoin = $this->accessor->callMethod('autoJoinSeparator', ['space', 'comma']);

        expect($rounded)->toBe(2)
            ->and($autoJoin)->toBe('comma')
            ->and(fn() => $this->accessor->callMethod('requireInteger', [new StringNode('oops'), 'list.nth() index']))
            ->toThrow(InvalidArgumentTypeException::class)
            ->and(fn() => $this->accessor->callMethod('requireInteger', [new NumberNode(2.5), 'list.nth() index']))
            ->toThrow(InvalidArgumentTypeException::class)
            ->and(fn() => $this->accessor->callMethod('resolveIndex', [1, 0, 'list.nth()']))
            ->toThrow(BuiltinArgumentException::class)
            ->and(fn() => $this->accessor->callMethod('resolveIndex', [0, 3, 'list.nth()']))
            ->toThrow(BuiltinArgumentException::class)
            ->and(fn() => $this->accessor->callMethod('resolveIndex', [4, 3, 'list.nth()']))
            ->toThrow(BuiltinArgumentException::class);
    });

    it('covers private separator and bracket helpers', function () {
        $auto              = $this->accessor->callMethod('resolveSeparator', [new StringNode('auto'), 'slash']);
        $slash             = $this->accessor->callMethod('resolveSeparator', [new StringNode('slash'), 'space']);
        $autoBracketed     = $this->accessor->callMethod('resolveBracketed', [new StringNode('auto'), false]);
        $boolBracketed     = $this->accessor->callMethod('resolveBracketed', [new BooleanNode(false), true]);
        $fallbackBracketed = $this->accessor->callMethod('resolveBracketed', [new NumberNode(1), false]);

        expect($auto)->toBe('slash')
            ->and($slash)->toBe('slash')
            ->and($autoBracketed)->toBeFalse()
            ->and($boolBracketed)->toBeFalse()
            ->and($fallbackBracketed)->toBeTrue()
            ->and(fn() => $this->accessor->callMethod('resolveSeparator', [new NumberNode(1), 'space']))
            ->toThrow(InvalidArgumentTypeException::class)
            ->and(fn() => $this->accessor->callMethod('resolveSeparator', [new StringNode('weird'), 'space']))
            ->toThrow(UnknownListSeparatorException::class);
    });
});
