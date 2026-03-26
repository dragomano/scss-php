<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\SassSelectorModule;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\StringNode;

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
        expect($result->value)->toBe('.btn.primary');
    });

    it('evaluates extend', function () {
        $result = $this->module->call('extend', [new StringNode('.button .icon'), new StringNode('.icon'), new StringNode('.glyph')], []);
        expect($result->value)->toBe('.button .icon, .button .glyph');
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

    it('evaluates nest', function () {
        $result = $this->module->call('nest', [new StringNode('.card'), new StringNode('&:hover')], []);
        expect($result->value)->toBe('.card:hover');
    });

    it('evaluates parse', function () {
        $result = $this->module->call('parse', [new StringNode('  .card   >  .title ')], []);
        expect($result->value)->toBe('.card > .title');
    });

    it('evaluates replace', function () {
        $result = $this->module->call('replace', [new StringNode('.button .icon'), new StringNode('.icon'), new StringNode('.badge')], []);
        expect($result->value)->toBe('.button .badge');
    });

    it('evaluates simple-selectors', function () {
        $result = $this->module->call('simple-selectors', [new StringNode('.btn.primary:hover')], []);

        expect($result)->toBeInstanceOf(ListNode::class)
            ->and($result->separator)->toBe('comma')
            ->and($result->items[0]->value)->toBe('.btn')
            ->and($result->items[1]->value)->toBe('.primary')
            ->and($result->items[2]->value)->toBe(':hover');
    });

    it('evaluates unify', function () {
        $result = $this->module->call('unify', [new StringNode('.button'), new StringNode('.primary')], []);
        expect($result->value)->toBe('.button.primary');
    });

    it('unifies complex selectors as intersection', function () {
        $result = $this->module->call('unify', [new StringNode('.warning a'), new StringNode('main a')], []);
        expect($result->value)->toBe('.warning main a, main .warning a');
    });
});
