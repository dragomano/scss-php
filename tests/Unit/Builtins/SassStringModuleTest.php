<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassStringModule;
use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

describe('SassStringModule', function () {
    beforeEach(function () {
        $this->module = new SassStringModule();
    });

    it('exposes metadata', function () {
        expect($this->module->getName())->toBe('string')
            ->and($this->module->getFunctions())->toBe([
                'index',
                'insert',
                'length',
                'quote',
                'slice',
                'split',
                'to-lower-case',
                'to-upper-case',
                'unique-id',
                'unquote',
            ])
            ->and($this->module->getGlobalAliases())->toHaveKeys([
                'str-length', 'str-insert', 'str-index', 'str-slice',
                'to-upper-case', 'to-lower-case', 'quote', 'unquote', 'unique-id',
            ]);
    });

    it('evaluates index', function () {
        $result = $this->module->call('index', [new StringNode('hello'), new StringNode('ll')], []);

        expect($result->value)->toBe(3);
    });

    it('evaluates insert', function () {
        $result = $this->module->call('insert', [new StringNode('abcd'), new StringNode('X'), new NumberNode(3)], []);

        expect($result->value)->toBe('abXcd');
    });

    it('clamps insert offsets to the string boundaries', function () {
        $beforeStart = $this->module->call('insert', [new StringNode('abcd'), new StringNode('X'), new NumberNode(-10)], []);
        $afterEnd    = $this->module->call('insert', [new StringNode('abcd'), new StringNode('X'), new NumberNode(99)], []);

        expect($beforeStart->value)->toBe('Xabcd')
            ->and($afterEnd->value)->toBe('abcdX');
    });

    it('evaluates length', function () {
        $result = $this->module->call('length', [new StringNode('hello')], []);

        expect($result->value)->toBe(5);
    });

    it('evaluates quote', function () {
        $result = $this->module->call('quote', [new StringNode('x')], []);

        expect($result->value)->toBe('x')
            ->and($result->quoted)->toBeTrue();
    });

    it('evaluates slice', function () {
        $result = $this->module->call('slice', [new StringNode('abcdef'), new NumberNode(2), new NumberNode(4)], []);

        expect($result->value)->toBe('bcd');
    });

    it('clamps slice bounds and returns empty strings for inverted ranges', function () {
        $startBeforeBeginning = $this->module->call('slice', [new StringNode('abcdef'), new NumberNode(-99), new NumberNode(2)], []);
        $endBeforeBeginning   = $this->module->call('slice', [new StringNode('abcdef'), new NumberNode(2), new NumberNode(-99)], []);
        $inverted             = $this->module->call('slice', [new StringNode('abcdef', true), new NumberNode(4), new NumberNode(2)], []);

        expect($startBeforeBeginning->value)->toBe('ab')
            ->and($endBeforeBeginning->value)->toBe('')
            ->and($inverted->value)->toBe('')
            ->and($inverted->quoted)->toBeTrue();
    });

    it('evaluates split', function () {
        $result = $this->module->call('split', [new StringNode('a-b-c'), new StringNode('-')], []);

        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('comma')
            ->and(count($result->items))->toBe(3);
    });

    it('validates split limits and keeps empty input as a single bracketed item', function () {
        $empty = $this->module->call('split', [new StringNode('', true), new StringNode('-')], []);

        expect(fn() => $this->module->call('split', [
            new StringNode('a-b'),
            new StringNode('-'),
        ], ['limit' => new StringNode('two')]))
            ->toThrow(MissingFunctionArgumentsException::class, 'expects an integer argument')
            ->and(fn() => $this->module->call('split', [
                new StringNode('a-b'),
                new StringNode('-'),
                new NumberNode(0),
            ], []))
            ->toThrow(BuiltinArgumentException::class)
            ->and($empty)->toBeInstanceOf(ListNode::class)
            ->and($empty->bracketed)->toBeTrue()
            ->and(count($empty->items))->toBe(1)
            ->and($empty->items[0])->toBeInstanceOf(StringNode::class)
            ->and($empty->items[0]->value)->toBe('')
            ->and($empty->items[0]->quoted)->toBeTrue();
    });

    it('evaluates to-lower-case', function () {
        $result = $this->module->call('to-lower-case', [new StringNode('AB')], []);

        expect($result->value)->toBe('ab');
    });

    it('evaluates to-upper-case', function () {
        $result = $this->module->call('to-upper-case', [new StringNode('ab')], []);

        expect($result->value)->toBe('AB');
    });

    it('evaluates unique-id', function () {
        $first  = $this->module->call('unique-id', [], []);
        $second = $this->module->call('unique-id', [], []);

        expect($first)->toBeInstanceOf(StringNode::class)
            ->and($second)->toBeInstanceOf(StringNode::class)
            ->and($first->value)->not->toBe($second->value);
    });

    it('evaluates unquote', function () {
        $result = $this->module->call('unquote', [new StringNode('"x"')], []);

        expect($result->value)->toBe('x');
    });

    it('accepts numbers where string coercion is supported and rejects other value types', function () {
        $length = $this->module->call('length', [new NumberNode(12, 'px')], []);

        expect($length->value)->toBe(4)
            ->and(fn() => $this->module->call('length', [new ListNode([])], []))
            ->toThrow(MissingFunctionArgumentsException::class, 'expects a string argument');
    });

    it('requires integer indexes for insert and slice', function () {
        expect(fn() => $this->module->call('insert', [
            new StringNode('abcd'),
            new StringNode('X'),
            new StringNode('3'),
        ], []))
            ->toThrow(MissingFunctionArgumentsException::class, 'expects an integer argument')
            ->and(fn() => $this->module->call('slice', [
                new StringNode('abcd'),
                new NumberNode(1.5),
            ], []))
            ->toThrow(MissingFunctionArgumentsException::class, 'expects an integer argument');
    });

    it('describes list color and unsupported raw arguments in deprecated global suggestions', function () {
        $warnings = [];
        $context  = new BuiltinCallContext(
            logWarning: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
            builtinDisplayName: 'str-length',
            rawArguments: [
                new ListNode([new StringNode('a'), new NumberNode(2)], 'space'),
                new ColorNode('#112233'),
                new FunctionNode('noop'),
            ],
        );

        $this->module->call('length', [new StringNode('hello')], [], $context);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('string.length(a 2, #112233, )');
    });

    it('describes bracketed list raw arguments in deprecated global suggestions', function () {
        $warnings = [];
        $context  = new BuiltinCallContext(
            logWarning: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
            builtinDisplayName: 'str-length',
            rawArguments: [
                new ListNode([new StringNode('a'), new StringNode('b')], 'comma', true),
            ],
        );

        $this->module->call('length', [new StringNode('hello')], [], $context);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('string.length([a, b])');
    });

    it('ignores named raw arguments in deprecated global suggestions', function () {
        $warnings = [];
        $context  = new BuiltinCallContext(
            logWarning: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
            builtinDisplayName: 'str-length',
            rawArguments: [
                new StringNode('raw'),
                new NamedArgumentNode('extra', new StringNode('ignored')),
            ],
        );

        $this->module->call('length', [new StringNode('hello')], [], $context);

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('string.length(raw)')
            ->and($warnings[0])->not->toContain('ignored');
    });
});
