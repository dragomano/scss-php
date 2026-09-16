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

describe('SassSelectorModule edge cases', function () {
    beforeEach(function () {
        $this->module = new SassSelectorModule();
    });

    it('rejects complex replacement target with multiple compounds', function () {
        expect(fn() => $this->module->call(
            'replace',
            [new StringNode('.a .b'), new StringNode('.x .y'), new StringNode('.z')],
            [],
        ))->toThrow(SassErrorException::class, "Can't extend complex selector .x .y.");
    });

    it('rejects parent selectors in extend and replace', function () {
        expect(fn() => $this->module->call('extend', [new StringNode('.a'), new StringNode('.b'), new StringNode('&')], []))
            ->toThrow(SassErrorException::class, "Parent selectors aren't allowed here.")
            ->and(fn() => $this->module->call('replace', [new StringNode('.a'), new StringNode('&'), new StringNode('.b')], []))
            ->toThrow(SassErrorException::class, "Parent selectors aren't allowed here.");
    });

    it('rejects unbalanced selector syntax', function () {
        expect(fn() => $this->module->call('parse', [new StringNode('a)')], []))
            ->toThrow(SassErrorException::class, 'expected more input.')
            ->and(fn() => $this->module->call('parse', [new StringNode('.a]')], []))
            ->toThrow(SassErrorException::class, 'expected more input.');
    });

    it('normalizes quoted and bracketed syntax when parsing', function () {
        $quoted  = $this->module->call('parse', [new StringNode('a[foo="("]')], []);
        $pseudo  = $this->module->call('nest', [new StringNode('.a'), new StringNode(':foo("x&y")')], []);
        $attrib  = $this->module->call('nest', [new StringNode('.a'), new StringNode('[b="c"]')], []);
        $nested  = $this->module->call('nest', [new StringNode('.a'), new StringNode('[[b]]')], []);

        expect(renderSelectorValue($quoted))->toBe('a[foo="("]')
            ->and(renderSelectorValue($pseudo))->toBe('.a :foo("x&y")')
            ->and(renderSelectorValue($attrib))->toBe('.a [b="c"]')
            ->and(renderSelectorValue($nested))->toBe('.a [[b]]');
    });

    it('describes invalid selector values with a nested list of non-strings', function () {
        expect(fn() => $this->module->call('parse', [new ListNode([new StringNode('.a'), new NullNode()], 'space', true)], []))
            ->toThrow(SassErrorException::class, '.a nullNode is not a valid selector');
    });

    it('rejects appending child selectors with leading combinators', function () {
        expect(fn() => $this->module->call('append', [new StringNode('.a'), new StringNode('> .b')], []))
            ->toThrow(SassErrorException::class, "Can't append > .b to .a.")
            ->and(fn() => $this->module->call('append', [new StringNode('.a'), new StringNode('+')], []))
            ->toThrow(SassErrorException::class, "Can't append + to .a.");
    });

    it('rejects appending namespaced and universal child selectors', function () {
        expect(fn() => $this->module->call('append', [new StringNode('.a'), new StringNode('ns|b')], []))
            ->toThrow(SassErrorException::class, "Can't append ns|b to .a.")
            ->and(fn() => $this->module->call('append', [new StringNode('.a'), new StringNode('*')], []))
            ->toThrow(SassErrorException::class, "Can't append * to .a.");
    });

    it('applies leading combinators when nesting parent references', function () {
        $withParentRef = $this->module->call('nest', [new StringNode('.a'), new StringNode('> &.b')], []);
        $alreadyLead   = $this->module->call('nest', [new StringNode('> .x'), new StringNode('> &:y')], []);

        expect(renderSelectorValue($withParentRef))->toBe('> .a.b')
            ->and(renderSelectorValue($alreadyLead))->toBe('> .x:y');
    });

    it('expands escaped parent references across multiple parents', function () {
        $single = $this->module->call('nest', [new StringNode('.a'), new StringNode('x\\&y')], []);
        $empty  = $this->module->call('nest', [new StringNode(''), new StringNode('x\\&y')], []);

        expect(renderSelectorValue($single))->toBe('.a')
            ->and(renderSelectorValue($empty))->toBe('x\\&y');
    });

    it('resolves escaped pseudo tokens containing parent references', function () {
        $noParen = $this->module->call('nest', [new StringNode('.a'), new StringNode('&((a):b\\&\\)')], []);
        $noAmp   = $this->module->call('nest', [new StringNode('.a'), new StringNode('&:a\\&(b)')], []);

        expect(renderSelectorValue($noParen))->toBe('.aa.a')
            ->and(renderSelectorValue($noAmp))->toBe('.a.a');
    });

    it('rejects trailing-combinator parents in compound selectors', function () {
        expect(fn() => $this->module->call('nest', [new StringNode('.a>'), new StringNode('&.c')], []))
            ->toThrow(SassErrorException::class, "can't be used as a parent in a compound selector");
    });

    it('keeps parent reference when parent compound has no tokens', function () {
        $result = $this->module->call('nest', [new StringNode('#'), new StringNode('&.c')], []);

        expect(renderSelectorValue($result))->toBe('&.c');
    });

    it('deduplicates identical complexes after nesting and unifying', function () {
        $unified = $this->module->call('unify', [new StringNode('.a,.a'), new StringNode('.b')], []);
        $nested  = $this->module->call('nest', [new StringNode('.a, .a'), new StringNode('&.b')], []);

        expect(renderSelectorValue($unified))->toBe('.a.b')
            ->and(renderSelectorValue($nested))->toBe('.a.b');
    });

    it('resolves parent references inside pseudo selector arguments', function () {
        $single = $this->module->call('nest', [new StringNode('.a'), new StringNode('a:not(&)')], []);
        $multi  = $this->module->call('nest', [new StringNode('.a, .b'), new StringNode('a:not(&)')], []);

        expect(renderSelectorValue($single))->toBe('a:not(.a)')
            ->and(renderSelectorValue($multi))->toBe('a:not(.a, .b)');
    });
});
