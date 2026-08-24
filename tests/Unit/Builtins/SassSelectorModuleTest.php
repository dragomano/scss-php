<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassSelectorModule;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;

function renderSelectorValue(AstNode $node): string
{
    if ($node instanceof NullNode) {
        return 'null';
    }

    if ($node instanceof StringNode) {
        return $node->value;
    }

    /** @var ListNode $node */
    return implode(', ', array_map(
        static function (AstNode $complex): string {
            if ($complex instanceof StringNode) {
                return $complex->value;
            }

            /** @var ListNode $complex */
            return implode(' ', array_map(
                static fn(AstNode $part): string => $part->value,
                $complex->items,
            ));
        },
        $node->items,
    ));
}

describe('SassSelectorModule', function () {
    beforeEach(function () {
        $this->module = new SassSelectorModule();
    });

    it('exposes metadata', function () {
        expect($this->module->getName())->toBe('selector')
            ->and($this->module->getFunctions())->toBe([
                'append',
                'extend',
                'is-superselector',
                'nest',
                'parse',
                'replace',
                'simple-selectors',
                'unify',
            ])
            ->and($this->module->getGlobalAliases())->toHaveKeys([
                'is-superselector',
                'simple-selectors',
                'selector-parse',
                'selector-nest',
                'selector-append',
                'selector-extend',
                'selector-replace',
                'selector-unify',
            ]);
    });

    it('evaluates append', function () {
        $result = $this->module->call('append', [new StringNode('.btn'), new StringNode('.primary')], []);

        expect(renderSelectorValue($result))->toBe('.btn.primary');
    });

    it('requires arguments for append and rejects parent references', function () {
        expect(fn() => $this->module->call('append', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('append', [new StringNode('.card'), new StringNode('&:hover')], []))
            ->toThrow(SassErrorException::class);
    });

    it('evaluates extend', function () {
        $result = $this->module->call('extend', [new StringNode('.button .icon'), new StringNode('.icon'), new StringNode('.glyph')], []);

        expect(renderSelectorValue($result))->toBe('.button .icon, .button .glyph');
    });

    it('requires arguments for extend and rejects complex targets', function () {
        expect(fn() => $this->module->call('extend', [new StringNode('.button'), new StringNode('.icon')], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('extend', [new StringNode('.button .icon'), new StringNode('> .icon'), new StringNode('.glyph')], []))
            ->toThrow(SassErrorException::class, 'Complex selectors may not be extended.');
    });

    it('extends selectors intelligently for nested and incompatible cases', function () {
        $result = $this->module->call(
            'extend',
            [
                new StringNode('p.info, .guide .info, main.content .info'),
                new StringNode('.info'),
                new StringNode('.content nav.sidebar'),
            ],
            [],
        );

        expect(renderSelectorValue($result))->toBe(
            'p.info, .guide .info, .guide .content nav.sidebar, .content .guide nav.sidebar, main.content .info, main.content nav.sidebar',
        );
    });

    it('evaluates is-superselector', function () {
        $result = $this->module->call('is-superselector', [new StringNode('.btn'), new StringNode('.btn.primary')], []);

        expect($result)->toBeInstanceOf(BooleanNode::class)
            ->and($result->value)->toBeTrue();
    });

    it('requires two selectors for is-superselector and returns true for equal selectors', function () {
        $result = $this->module->call('is-superselector', [new StringNode('.btn'), new StringNode('.btn')], []);

        expect($result->value)->toBeTrue()
            ->and(fn() => $this->module->call('is-superselector', [new StringNode('.btn')], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates nest', function () {
        $result = $this->module->call('nest', [new StringNode('.card'), new StringNode('&:hover')], []);

        expect(renderSelectorValue($result))->toBe('.card:hover');
    });

    it('requires arguments for nest', function () {
        expect(fn() => $this->module->call('nest', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates parse', function () {
        $result = $this->module->call('parse', [new StringNode('  .card   >  .title ')], []);

        expect(renderSelectorValue($result))->toBe('.card > .title');
    });

    it('evaluates replace', function () {
        $result = $this->module->call('replace', [new StringNode('.button .icon'), new StringNode('.icon'), new StringNode('.badge')], []);

        expect(renderSelectorValue($result))->toBe('.button .badge');
    });

    it('rejects complex replacement targets', function () {
        expect(fn() => $this->module->call(
            'replace',
            [new StringNode('.button > .icon'), new StringNode('> .icon'), new StringNode('> .badge')],
            [],
        ))->toThrow(SassErrorException::class);
    });

    it('extends with a complex extender', function () {
        $result = $this->module->call(
            'extend',
            [new StringNode('.button .icon'), new StringNode('.icon'), new StringNode('> .badge')],
            [],
        );

        expect(renderSelectorValue($result))->toBe('.button .icon, .button > .badge');
    });

    it('keeps the selector unchanged when extend does not find the target', function () {
        $result = $this->module->call(
            'extend',
            [new StringNode('.button .label'), new StringNode('.icon'), new StringNode('> .badge')],
            [],
        );

        expect(renderSelectorValue($result))->toBe('.button .label');
    });

    it('requires arguments for replace', function () {
        expect(fn() => $this->module->call('replace', [new StringNode('.button'), new StringNode('.icon')], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates simple-selectors', function () {
        $result = $this->module->call('simple-selectors', [new StringNode('.btn.primary:hover')], []);

        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('comma')
            ->and($result->items[0]->value)->toBe('.btn')
            ->and($result->items[1]->value)->toBe('.primary')
            ->and($result->items[2]->value)->toBe(':hover');
    });

    it('requires a string selector for simple-selectors', function () {
        expect(fn() => $this->module->call('simple-selectors', [], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('simple-selectors', [new ListNode([])], []))
            ->toThrow(SassErrorException::class);
    });

    it('evaluates unify', function () {
        $result = $this->module->call('unify', [new StringNode('.button'), new StringNode('.primary')], []);

        expect(renderSelectorValue($result))->toBe('.button.primary');
    });

    it('requires two string selectors for unify and rejects parent references', function () {
        expect(fn() => $this->module->call('unify', [new StringNode('.button')], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and($this->module->call('unify', [new ListNode([]), new StringNode('.button')], []))
            ->toBeInstanceOf(NullNode::class)
            ->and(fn() => $this->module->call('unify', [new StringNode('.button'), new StringNode('&:hover')], []))
            ->toThrow(SassErrorException::class);
    });

    it('unifies complex selectors as intersection', function () {
        $result = $this->module->call('unify', [new StringNode('.warning a'), new StringNode('main a')], []);

        expect(renderSelectorValue($result))->toBe('.warning main a, main .warning a');
    });

    it('unifies selectors with leading combinators and returns null when incompatible', function () {
        $leading = $this->module->call('unify', [new StringNode('> .c'), new StringNode('.d')], []);
        $incompatible = $this->module->call('unify', [new StringNode('a'), new StringNode('b')], []);
        $empty = $this->module->call('unify', [new StringNode(''), new StringNode('.button')], []);

        expect(renderSelectorValue($leading))->toBe('> .c.d')
            ->and($incompatible)->toBeInstanceOf(NullNode::class)
            ->and($empty)->toBeInstanceOf(NullNode::class);
    });

    it('falls back when structured replacement target has no selector tokens', function () {
        $result = $this->module->call(
            'replace',
            [new StringNode('.button'), new StringNode('#'), new StringNode('.badge')],
            [],
        );

        expect(renderSelectorValue($result))->toBe('.button');
    });

    it('prunes covered ancestor compounds while unifying selectors', function () {
        $result = $this->module->call('unify', [new StringNode('.foo .bar'), new StringNode('.foo .baz')], []);

        expect(renderSelectorValue($result))->toBe('.foo .bar.baz');
    });

    it('rejects bogus compounds in simple-selectors', function () {
        expect(fn() => $this->module->call('simple-selectors', [new StringNode('.')], []))
            ->toThrow(SassErrorException::class);
    });
});
