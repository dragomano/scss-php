<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassSelectorModule;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\ReflectionAccessor;

describe('SassSelectorModule', function () {
    beforeEach(function () {
        $this->module   = new SassSelectorModule();
        $this->accessor = new ReflectionAccessor($this->module);
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
        expect($result->value)->toBe('.btn.primary');
    });

    it('requires arguments for append and replaces parent references', function () {
        $result = $this->module->call('append', [new StringNode('.card'), new StringNode('&:hover')], []);

        expect($result->value)->toBe('.card:hover')
            ->and(fn() => $this->module->call('append', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates extend', function () {
        $result = $this->module->call('extend', [new StringNode('.button .icon'), new StringNode('.icon'), new StringNode('.glyph')], []);
        expect($result->value)->toBe('.button .icon, .button .glyph');
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
            []
        );

        expect($result->value)->toBe(
            'p.info, .guide .info, .guide .content nav.sidebar, .content .guide nav.sidebar, main.content .info, main.content nav.sidebar'
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
        expect($result->value)->toBe('.card:hover');
    });

    it('requires arguments for nest', function () {
        expect(fn() => $this->module->call('nest', [], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates parse', function () {
        $result = $this->module->call('parse', [new StringNode('  .card   >  .title ')], []);
        expect($result->value)->toBe('.card > .title');
    });

    it('evaluates replace', function () {
        $result = $this->module->call('replace', [new StringNode('.button .icon'), new StringNode('.icon'), new StringNode('.badge')], []);
        expect($result->value)->toBe('.button .badge');
    });

    it('falls back to plain replacement when structured replacement is unavailable', function () {
        $result = $this->module->call(
            'replace',
            [new StringNode('.button > .icon'), new StringNode('> .icon'), new StringNode('> .badge')],
            []
        );

        expect($result->value)->toBe('.button > .badge');
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
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('evaluates unify', function () {
        $result = $this->module->call('unify', [new StringNode('.button'), new StringNode('.primary')], []);
        expect($result->value)->toBe('.button.primary');
    });

    it('requires two string selectors for unify and handles parent references', function () {
        $withParent = $this->module->call('unify', [new StringNode('.button'), new StringNode('&:hover')], []);

        expect($withParent->value)->toBe('.button:hover')
            ->and(fn() => $this->module->call('unify', [new StringNode('.button')], []))
            ->toThrow(MissingFunctionArgumentsException::class)
            ->and(fn() => $this->module->call('unify', [new ListNode([]), new StringNode('.button')], []))
            ->toThrow(MissingFunctionArgumentsException::class);
    });

    it('unifies complex selectors as intersection', function () {
        $result = $this->module->call('unify', [new StringNode('.warning a'), new StringNode('main a')], []);
        expect($result->value)->toBe('.warning main a, main .warning a');
    });

    it('returns null for incompatible unifications and covers selector helpers', function () {
        $result = $this->module->call('unify', [new StringNode('div'), new StringNode('span')], []);
        $emptyTarget = $this->accessor->callMethod('replaceExtendTargetInStructuredSelectorPart', ['.a', '', '.b']);
        $rawPositional = $this->accessor->callMethod('rawPositionalArguments');
        $emptyFirst = $this->accessor->callMethod('unifySelectorParts', ['', '.a']);
        $unsupported = $this->accessor->callMethod('unifySelectorParts', ['> .a', '.b']);
        $emptyCompounds = $this->accessor->callMethod('unifySelectorParts', [' ', '*']);
        $nullSubject = $this->accessor->callMethod('unifySelectorParts', ['div', 'span']);
        $pruned = $this->accessor->callMethod('pruneCoveredCompounds', [['.a'], ['.a.b']]);
        $selectorParts = $this->accessor->callMethod('selectorParts', [':']);

        expect($result)->toBeInstanceOf(NullNode::class)
            ->and($emptyTarget)->toBeNull()
            ->and($rawPositional)->toBe([])
            ->and($emptyFirst)->toBe([])
            ->and($unsupported)->toBe([])
            ->and($emptyCompounds)->toBe([])
            ->and($nullSubject)->toBe([])
            ->and($pruned)->toBe([])
            ->and($selectorParts)->toBe([':']);
    });
});
