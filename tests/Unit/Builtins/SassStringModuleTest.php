<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassStringModule;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

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

    it('evaluates split', function () {
        $result = $this->module->call('split', [new StringNode('a-b-c'), new StringNode('-')], []);

        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('comma')
            ->and(count($result->items))->toBe(3);
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
});
