<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\SelectorComponent;
use Bugo\SCSS\Utils\SelectorTokenizer;

describe('SelectorTokenizer', function () {
    beforeEach(function () {
        $this->tokenizer = new SelectorTokenizer();
    });

    it('tokenizeCompound() splits simple element selector', function () {
        expect($this->tokenizer->tokenizeCompound('div'))->toBe(['div']);
    });

    it('tokenizeCompound() splits class and element', function () {
        $tokens = $this->tokenizer->tokenizeCompound('div.foo');
        expect($tokens)->toContain('div')
            ->and($tokens)->toContain('.foo');
    });

    it('tokenizeCompound() handles id selector', function () {
        $tokens = $this->tokenizer->tokenizeCompound('#main');
        expect($tokens)->toContain('#main');
    });

    it('tokenizeCompound() handles pseudo-class', function () {
        $tokens = $this->tokenizer->tokenizeCompound('a:hover');
        expect($tokens)->toContain(':hover');
    });

    it('tokenizeCompound() handles pseudo-class functions with bracket groups', function () {
        $tokens = $this->tokenizer->tokenizeCompound('a:not(.foo, .bar)');

        expect($tokens)->toContain(':not(.foo, .bar)');
    });

    it('tokenizeCompound() handles pseudo-element', function () {
        $tokens = $this->tokenizer->tokenizeCompound('p::before');
        expect($tokens)->toContain('::before');
    });

    it('tokenizeCompound() handles attribute selector', function () {
        $tokens = $this->tokenizer->tokenizeCompound('[type="text"]');
        expect($tokens)->toContain('[type=text]');
    });

    it('tokenizeCompound() handles universal selector', function () {
        $tokens = $this->tokenizer->tokenizeCompound('*');
        expect($tokens)->toContain('*');
    });

    it('doesCompoundSatisfy() returns true when candidate matches required', function () {
        expect($this->tokenizer->doesCompoundSatisfy('div.foo.bar', '.foo'))->toBeTrue();
    });

    it('doesCompoundSatisfy() returns false when candidate missing required token', function () {
        expect($this->tokenizer->doesCompoundSatisfy('div.bar', '.foo'))->toBeFalse();
    });

    it('doesCompoundSatisfy() returns false for empty candidate with non-empty requirement', function () {
        expect($this->tokenizer->doesCompoundSatisfy('', '.foo'))->toBeFalse();
    });

    it('doesCompoundSatisfy() returns true for empty required', function () {
        expect($this->tokenizer->doesCompoundSatisfy('div', ''))->toBeTrue();
    });

    it('unifyCompounds() merges two compatible selectors', function () {
        $result = $this->tokenizer->unifyCompounds('.foo', '.bar');
        expect($result)->toContain('foo')
            ->and($result)->toContain('bar');
    });

    it('unifyCompounds() returns empty string when both compounds are empty', function () {
        expect($this->tokenizer->unifyCompounds('', ''))->toBe('');
    });

    it('unifyCompounds() skips duplicate non-type tokens from the right side', function () {
        expect($this->tokenizer->unifyCompounds('.foo', '.foo'))->toBe('.foo');
    });

    it('unifyCompounds() returns universal selector when only universal selectors remain', function () {
        expect($this->tokenizer->unifyCompounds('*', '*'))->toBe('*');
    });

    it('unifyCompounds() returns null for incompatible element types', function () {
        $result = $this->tokenizer->unifyCompounds('div', 'span');
        expect($result)->toBeNull();
    });

    it('unifyCompounds() returns null for different ids', function () {
        $result = $this->tokenizer->unifyCompounds('#foo', '#bar');
        expect($result)->toBeNull();
    });

    it('hasUnsupportedTopLevelCombinator() detects child combinator', function () {
        expect($this->tokenizer->hasUnsupportedTopLevelCombinator('div > span'))->toBeTrue();
    });

    it('hasUnsupportedTopLevelCombinator() detects adjacent sibling combinator', function () {
        expect($this->tokenizer->hasUnsupportedTopLevelCombinator('h1 + p'))->toBeTrue();
    });

    it('hasUnsupportedTopLevelCombinator() returns false for descendant selector', function () {
        expect($this->tokenizer->hasUnsupportedTopLevelCombinator('div span'))->toBeFalse();
    });

    it('hasUnsupportedTopLevelCombinator() ignores combinators inside parentheses', function () {
        expect($this->tokenizer->hasUnsupportedTopLevelCombinator(':is(a > b)'))->toBeFalse();
    });

    it('hasUnsupportedTopLevelCombinator() ignores combinators inside quoted attribute values', function () {
        expect($this->tokenizer->hasUnsupportedTopLevelCombinator('[data-test="a>b"] span'))->toBeFalse();
    });

    it('hasBogusTopLevelCombinatorSequence() detects repeated top-level combinators', function () {
        expect($this->tokenizer->hasBogusTopLevelCombinatorSequence('div > + span'))->toBeTrue();
    });

    it('hasBogusTopLevelCombinatorSequence() detects nested combinator sequences', function () {
        expect($this->tokenizer->hasBogusTopLevelCombinatorSequence(':is(div > + span) a'))->toBeTrue();
    });

    it('hasBogusTopLevelCombinatorSequence() ignores quoted attribute content and still detects top-level repeats', function () {
        expect($this->tokenizer->hasBogusTopLevelCombinatorSequence('[data-test="a>b"] > + span'))->toBeTrue();
    });

    describe('hasAdjacentCompoundSelectors()', function () {
        it('detects attribute selector immediately followed by type selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[class]a'))->toBeTrue();
        });

        it('detects attribute selector immediately followed by type selector with value', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[href="x"]div'))->toBeTrue();
        });

        it('detects attribute selector immediately followed by type selector (span)', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[attr]span'))->toBeTrue();
        });

        it('detects universal selector immediately followed by type selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('*div'))->toBeTrue();
        });

        it('detects adjacent compounds in a selector list', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('.ok, [class]a'))->toBeTrue();
        });
        it('returns false for type selector followed by attribute selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('a[href]'))->toBeFalse();
        });

        it('returns false for type selector followed by class selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div.foo'))->toBeFalse();
        });

        it('returns false for type selector followed by id selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div#main'))->toBeFalse();
        });

        it('returns false for type selector followed by pseudo-class', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('a:hover'))->toBeFalse();
        });

        it('returns false for multiple class selectors in one compound', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('.foo.bar.baz'))->toBeFalse();
        });

        it('returns false for class and id in one compound', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('.foo#bar'))->toBeFalse();
        });

        it('returns false for attribute followed by class selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[class].foo'))->toBeFalse();
        });

        it('returns false for attribute followed by pseudo-class', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[disabled]:hover'))->toBeFalse();
        });

        it('returns false for whitespace-separated selectors', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div span'))->toBeFalse();
        });

        it('returns false for child combinator separated selectors', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div > span'))->toBeFalse();
        });

        it('returns false for adjacent sibling combinator separated selectors', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div + span'))->toBeFalse();
        });

        it('returns false for general sibling combinator separated selectors', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div ~ span'))->toBeFalse();
        });

        it('returns false for complex valid selector with multiple compounds', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div.foo > a[href]:hover'))->toBeFalse();
        });

        it('returns false for universal selector alone', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('*'))->toBeFalse();
        });

        it('returns false for pseudo-element selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('p::before'))->toBeFalse();
        });

        it('returns false for quoted attribute value containing a letter', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[data-type="adiv"]'))->toBeFalse();
        });

        it('returns false for attribute with single-quoted value containing a letter', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors("[data-type='adiv']"))->toBeFalse();
        });

        it('returns false for attribute with quoted value where closing quote matches opening', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[href="http://example.com"]'))->toBeFalse();
        });

        it('returns false for pseudo-element with double colon', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('[disabled]::after'))->toBeFalse();
        });

        it('returns false for pseudo-class with function argument', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('li:nth-child(2n+1)'))->toBeFalse();
        });

        it('detects adjacent compound after pseudo-class function', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors(':not(.x)div'))->toBeTrue();
        });

        it('returns false for id selector in compound', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('div#main'))->toBeFalse();
        });

        it('returns false for interpolation followed by class', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('#{$x}.foo'))->toBeFalse();
        });

        it('detects adjacent compound after interpolation', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('#{$x}div'))->toBeTrue();
        });

        it('returns false for pseudo-class with nested parentheses', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors(':is(:not(.a))'))->toBeFalse();
        });

        it('detects adjacent compound after pseudo with nested parens', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors(':is(:not(.a))div'))->toBeTrue();
        });
    });

    it('interleaveSequences() returns both orderings', function () {
        $result = $this->tokenizer->interleaveSequences(['a', 'b'], ['c']);
        expect(count($result))->toBe(2);
    });

    it('interleaveSequences() returns single sequence when one side empty', function () {
        expect($this->tokenizer->interleaveSequences([], ['x']))->toBe([['x']])
            ->and($this->tokenizer->interleaveSequences(['y'], []))->toBe([['y']]);
    });

    it('orderTokens() puts ids first, then classes, then pseudos', function () {
        $ordered = $this->tokenizer->orderTokens(['.bar', '#foo', ':hover']);
        expect($ordered[0])->toBe('#foo')
            ->and($ordered[1])->toBe('.bar')
            ->and($ordered[2])->toBe(':hover');
    });

    it('orderTokens() places type selectors before pseudo-elements', function () {
        expect($this->tokenizer->orderTokens(['div', '::before']))->toBe(['div', '::before']);
    });

    it('orderTokens() ignores empty tokens while preserving selector ordering', function () {
        expect($this->tokenizer->orderTokens(['', '.bar', '#foo', ':hover']))->toBe(['#foo', '.bar', ':hover']);
    });

    it('extractTypeToken() returns element name', function () {
        $tokens = $this->tokenizer->tokenizeCompound('div.foo');

        expect($this->tokenizer->extractTypeToken($tokens))->toBe('div');
    });

    it('extractTypeToken() skips attributes and returns the universal selector when no type token exists', function () {
        expect($this->tokenizer->extractTypeToken(['[type="text"]', '*']))->toBe('*');
    });

    it('extractIdToken() returns id token', function () {
        $tokens = $this->tokenizer->tokenizeCompound('#main.foo');

        expect($this->tokenizer->extractIdToken($tokens))->toBe('#main');
    });

    it('removeTokensFromCompound() removes matching tokens', function () {
        $result = $this->tokenizer->removeTokensFromCompound('div.foo.bar', ['.foo']);
        expect($result)->toBe('div.bar');
    });

    it('removeTokensFromCompound() returns null if target token not found', function () {
        $result = $this->tokenizer->removeTokensFromCompound('div.bar', ['.foo']);
        expect($result)->toBeNull();
    });

    it('removeTokensFromCompound() returns null for an empty compound', function () {
        expect($this->tokenizer->removeTokensFromCompound('', ['.foo']))->toBeNull();
    });

    it('replaceTokensInCompound() returns null for an empty compound', function () {
        expect($this->tokenizer->replaceTokensInCompound('', ['.foo'], '.bar'))->toBeNull();
    });

    it('replaceTokensInCompound() returns null when target tokens are not present', function () {
        expect($this->tokenizer->replaceTokensInCompound('div.bar', ['.foo'], '.baz'))->toBeNull();
    });

    it('replaceTokensInCompound() returns null when replacement conflicts with remaining type', function () {
        expect($this->tokenizer->replaceTokensInCompound('div.foo', ['.foo'], 'span'))->toBeNull();
    });

    it('splitAtTopLevel() splits by comma at top level', function () {
        $result = $this->tokenizer->splitAtTopLevel('a, b, c', [',']);
        expect($result)->toBe(['a', 'b', 'c']);
    });

    it('splitAtTopLevel() does not split inside parentheses', function () {
        $result = $this->tokenizer->splitAtTopLevel(':is(a, b), c', [',']);
        expect($result)->toBe([':is(a, b)', 'c']);
    });

    it('splitAtTopLevel() with handleQuotes ignores separators inside quoted strings', function () {
        $result = $this->tokenizer->splitAtTopLevel('a["x,y"], b', [','], true);
        expect($result)->toBe(['a["x,y"]', 'b']);
    });

    describe('tokenizeCompound() additional coverage', function () {
        it('skips unknown characters like combinators', function () {
            $tokens = $this->tokenizer->tokenizeCompound('a > b');
            expect($tokens)->toContain('a')
                ->and($tokens)->toContain('b')
                ->and($tokens)->not->toContain('>');
        });

        it('continues parsing after bracket group', function () {
            $tokens = $this->tokenizer->tokenizeCompound('[type="text"]div');
            expect($tokens)->toContain('[type=text]')
                ->and($tokens)->toContain('div');
        });
    });

    describe('doesCompoundSatisfy() additional coverage', function () {
        it('returns false when required type does not match candidate type', function () {
            expect($this->tokenizer->doesCompoundSatisfy('.foo', 'div'))->toBeFalse();
        });

        it('returns false when candidate has universal type but required has specific type', function () {
            expect($this->tokenizer->doesCompoundSatisfy('*', 'div'))->toBeFalse();
        });

        it('skips wildcards and empty tokens when checking required tokens', function () {
            expect($this->tokenizer->doesCompoundSatisfy('div.foo', '*'))->toBeTrue();
        });

        it('skips required type token when checking required tokens', function () {
            expect($this->tokenizer->doesCompoundSatisfy('div.foo', 'div'))->toBeTrue();
        });
    });

    describe('unifyCompounds() additional coverage', function () {
        it('returns null when candidate type is incompatible with required type via left', function () {
            expect($this->tokenizer->unifyCompounds('span.foo', 'div.bar'))->toBeNull();
        });

        it('resolves type from right when left type is universal', function () {
            $result = $this->tokenizer->unifyCompounds('*', 'div');
            expect($result)->toBe('div');
        });

        it('resolves type from left when left type is specific', function () {
            $result = $this->tokenizer->unifyCompounds('div', '*');
            expect($result)->toBe('div');
        });
    });

    describe('replaceTokensInCompound() additional coverage', function () {
        it('puts replacement tokens before remaining when target type exists', function () {
            $result = $this->tokenizer->replaceTokensInCompound('div.foo', ['div'], 'span');
            expect($result)->toBe('span.foo');
        });

        it('normalizes pseudo order when remaining has both pseudo and class-like tokens', function () {
            $result = $this->tokenizer->replaceTokensInCompound('.foo:hover', ['.foo'], '.bar');
            expect($result)->toBe('.bar:hover');
        });

        it('returns replacement when target tokens fully match compound', function () {
            $result = $this->tokenizer->replaceTokensInCompound('.foo', ['.foo'], '.bar');
            expect($result)->toBe('.bar');
        });

        it('skips duplicate tokens from replacement when target type exists', function () {
            $result = $this->tokenizer->replaceTokensInCompound('.foo.bar', ['.foo'], '.bar');
            expect($result)->toBe('.bar');
        });

        it('puts remaining tokens before replacement when no target type', function () {
            $result = $this->tokenizer->replaceTokensInCompound('.foo:hover', [':hover'], '.baz');
            expect($result)->toBe('.foo.baz');
        });
    });

    describe('hasBogusTopLevelCombinatorSequence() additional coverage', function () {
        it('resets combinator state after non-space non-combinator character', function () {
            expect($this->tokenizer->hasBogusTopLevelCombinatorSequence('div > a + b'))->toBeFalse();
        });
    });

    describe('hasUnsupportedTopLevelCombinator() additional coverage', function () {
        it('detects general sibling combinator', function () {
            expect($this->tokenizer->hasUnsupportedTopLevelCombinator('div ~ span'))->toBeTrue();
        });

        it('ignores combinators inside single-quoted attribute values', function () {
            expect($this->tokenizer->hasUnsupportedTopLevelCombinator("[data-test='a>b'] span"))->toBeFalse();
        });
    });

    describe('hasAdjacentCompoundSelectors() additional coverage', function () {
        it('handles hash interpolation followed by type selector', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('#{$var}span'))->toBeTrue();
        });

        it('returns false for hash interpolation followed by class', function () {
            expect($this->tokenizer->hasAdjacentCompoundSelectors('#{$var}.foo'))->toBeFalse();
        });
    });

    describe('splitAtTopLevel() additional coverage', function () {
        it('handles bracket depth tracking', function () {
            $result = $this->tokenizer->splitAtTopLevel('a[b], c', [',']);
            expect($result)->toBe(['a[b]', 'c']);
        });

        it('handles paren depth tracking', function () {
            $result = $this->tokenizer->splitAtTopLevel('calc(1 + 2), b', [',']);
            expect($result)->toBe(['calc(1 + 2)', 'b']);
        });

        it('handles single-quoted attribute values with handleQuotes', function () {
            $result = $this->tokenizer->splitAtTopLevel("a['x,y'], b", [','], true);
            expect($result)->toBe(["a['x,y']", 'b']);
        });
    });

    describe('inspectTopLevelCombinators() additional coverage', function () {
        it('handles single-quoted attribute values', function () {
            expect($this->tokenizer->hasUnsupportedTopLevelCombinator("[href='http://example.com'] span"))->toBeFalse();
        });

        it('detects combinators after closing paren', function () {
            expect($this->tokenizer->hasUnsupportedTopLevelCombinator(':is(a) > b'))->toBeTrue();
        });
    });

});

describe('SelectorTokenizer 100% lines', function () {
    beforeEach(function () {
        $this->tokenizer = new SelectorTokenizer();
    });

    it('covers compound helpers and bogus checks', function () {
        expect($this->tokenizer->replaceTokensInCompound('a:hover.foo', ['a'], 'b'))->toBe('b.foo:hover')
            ->and($this->tokenizer->replaceTokensInCompound('a:hover::before', ['a'], 'b'))->toBe('b:hover::before')
            ->and($this->tokenizer->replaceTokensInCompound('.foo:hover', ['.foo'], 'b'))->toBe('b:hover')
            ->and($this->tokenizer->doesCompoundSatisfy('*', '.foo'))->toBeTrue()
            ->and($this->tokenizer->hasBogusTrailingCombinator(' '))->toBeTrue()
            ->and($this->tokenizer->hasBogusSelectorPseudoCombinator(':not(>'))->toBeFalse()
            ->and($this->tokenizer->normalizePseudoArguments('q"a(b"r:is(z)'))->toBe('q"a(b"r:is(z)')
            ->and($this->tokenizer->normalizePseudoArguments('q"a(b'))->toBe('q"a(b')
            ->and($this->tokenizer->normalizePseudoArguments('q"a\\cb"r:is(z)'))->toBe('q"a\\cb"r:is(z)')
            ->and($this->tokenizer->normalizeNthArguments('a:nth-child *'))->toBe('a:nth-child *')
            ->and($this->tokenizer->normalizeExtendPart(' '))->toBe('');
    });

    it('weaveExtendedSelector() handles bogus input and invalid replacements', function () {
        expect($this->tokenizer->weaveExtendedSelector('a > > b', 'a', 'b'))->toBe([])
            ->and($this->tokenizer->weaveExtendedSelector('a', 'a', ' '))->toBe([])
            ->and($this->tokenizer->weaveExtendedSelector('> a', 'a', '+ b'))->toBe([])
            ->and($this->tokenizer->weaveExtendedSelector('a#a1 a', 'a', '#q'))->toBe(['a#a1 #q'])
            ->and($this->tokenizer->weaveExtendedSelector('b a', 'a', '> c'))->toBe(['> b c'])
            ->and($this->tokenizer->weaveExtendedSelector('t#1 t', 't', '#2'))->toBe(['t#1 #2']);
    });

    it('weaveExtendedSelector() weaves multi-compound extenders', function () {
        expect($this->tokenizer->weaveExtendedSelector('x t', 't', 'p q'))->toBe(['x p q', 'p x q'])
            ->and($this->tokenizer->weaveExtendedSelector('t x', 't', 'p q'))->toBe(['p q x'])
            ->and($this->tokenizer->weaveExtendedSelector('t', 't', '> p q'))->toBe(['> p q'])
            ->and($this->tokenizer->weaveExtendedSelector('t#1 t', 't', 'p#2 q'))
            ->toBe(['p#2 q#1 t', 't#1 p#2 q', 'p#2 t#1 q'])
            ->and($this->tokenizer->weaveExtendedSelector('t#1 t', 't', 'k #2t'))
            ->toBe(['t#1 k #2t', 'k t#1 #2t'])
            ->and($this->tokenizer->weaveExtendedSelector('t > t', 't', 'c2 q2'))
            ->toBe(['c2 q2 > t', 'c2 t > q2']);
    });

    it('textContainsParentSelector(), unifyComplexes() and unifyCompoundsStrict() edge cases', function () {
        expect($this->tokenizer->textContainsParentSelector('"&"'))->toBeFalse()
            ->and($this->tokenizer->textContainsParentSelector(':isn(a(b)) &'))->toBeTrue()
            ->and($this->tokenizer->unifyComplexes(
                $this->tokenizer->parseComplexComponents('a >'),
                $this->tokenizer->parseComplexComponents('b +'),
            ))->toBeNull()
            ->and($this->tokenizer->unifyComplexes(
                $this->tokenizer->parseComplexComponents('a.x'),
                $this->tokenizer->parseComplexComponents('a.y +'),
            ))->toEqual([[new SelectorComponent('a.x.y', '+')]])
            ->and($this->tokenizer->weave([]))->toBe([])
            ->and($this->tokenizer->unifyCompoundsStrict('a', '::before::after::x'))->toBeNull();
    });

    it('replaceSelectorTargetInComplexes() preserves and replaces targets', function () {
        expect($this->tokenizer->replaceSelectorTargetInComplexes(['a > b'], 'c', 'd'))->toBe(['a > b'])
            ->and($this->tokenizer->replaceSelectorTargetInComplexes([''], '.x', 'd'))->toBe([''])
            ->and($this->tokenizer->replaceSelectorTargetInComplexes(['a'], '.x', ''))->toBe(['a'])
            ->and($this->tokenizer->replaceSelectorTargetInComplexes(['a t'], 't', 'a b'))->toBe(['a b'])
            ->and($this->tokenizer->replaceSelectorTargetInComplexes([':is(x)'], 't', 'q'))->toBe([':is(x)'])
            ->and($this->tokenizer->replaceSelectorTargetInComplexes([':nth-child(2n of t)'], 't', 's'))
            ->toBe([':nth-child(2n of s)'])
            ->and($this->tokenizer->normalizeSelectorAttributes('[=x]'))->toBe('[=x]')
            ->and($this->tokenizer->normalizeSelectorAttributes('[a="b'))->toBe('[a="]')
            ->and($this->tokenizer->normalizeSelectorAttributes('[a=""]'))->toBe('[a=""]')
            ->and($this->tokenizer->normalizeSelectorAttributes('[a="b]'))->toBe('[a="b]');
    });

    it('tokenizeCompound() handles escape sequences', function () {
        expect($this->tokenizer->tokenizeCompound('\\41x'))->toBe(['\\41x'])
            ->and($this->tokenizer->tokenizeCompound('\\'))->toBe([])
            ->and($this->tokenizer->tokenizeCompound('a\\'))->toBe(['a'])
            ->and($this->tokenizer->tokenizeCompound('\\41 x'))->toBe(['\\41 x']);
    });

    it('extendSelectorPartByTargets() skips ineligible extenders and targets', function () {
        $t = $this->tokenizer;

        expect($t->extendSelectorPartByTargets('a', ['a'], ['']))->toBe([])
            ->and($t->extendSelectorPartByTargets('a', ['a b'], ['b']))->toBe(['a'])
            ->and($t->extendSelectorPartByTargets('a', ['> a'], ['b']))->toBe(['a'])
            ->and($t->extendSelectorPartByTargets('a', [']'], ['b']))->toBe(['a'])
            ->and($t->extendSelectorPartByTargets(' ', ['a'], ['b']))->toBe([])
            ->and($t->extendSelectorPartByTargets('--is(a)', ['a'], ['b']))->toBe(['--is(a)'])
            ->and($t->extendSelectorPartByTargets(':is()', ['a'], ['b']))->toBe([':is()'])
            ->and($t->extendSelectorPartByTargets(':not()', ['a'], ['b']))->toBe([':not()'])
            ->and($t->extendSelectorPartByTargets(' ', ['a'], ['>']))->toBe([])
            ->and($t->extendSelectorPartByTargets('a', [''], ['>']))->toBe([])
            ->and($t->extendSelectorPartByTargets('a', [' boast]'], ['>']))->toBe([])
            ->and($t->extendSelectorPartByTargets('a', [']'], ['>']))->toBe([]);
    });

    it('extendSelectorPartByTargets() extends inside selector pseudos', function () {
        $t = $this->tokenizer;

        expect($t->extendSelectorPartByTargets(':is(a)', ['a'], ['a', '']))->toBe([':is(a, a)'])
            ->and($t->extendSelectorPartByTargets(':is(a)', ['a'], ['a', ':not(b)']))->toBe([':is(a, a)'])
            ->and($t->extendSelectorPartByTargets(':is(:is(a))', ['a'], ['b']))->toBe([':is(a, b)'])
            ->and($t->extendSelectorPartByTargets(':is(:is(x))', ['a'], ['b']))->toBe([':is(:is(x))'])
            ->and($t->extendSelectorPartByTargets(':is(> .x, q2:not(.t))', ['.t'], ['b']))
            ->toBe([':is(> .x, q2:not(.t), b)']);
    });

    it('extendSelectorPartByTargets() modifies not pseudo lists and tokens', function () {
        $t = $this->tokenizer;

        expect($t->extendSelectorPartByTargets(':not(a, x)', ['a', '> a'], ['c']))->toBe([':not(a, c, x)'])
            ->and($t->extendSelectorPartByTargets(':not(a, x)', ['a'], [':not(b)']))->toBe([':not(a, x)'])
            ->and($t->extendSelectorPartByTargets(':not(a, x)', ['> a'], ['c']))->toBe([':not(a, x)'])
            ->and($t->extendSelectorPartByTargets(':not(a, x)', ['z'], ['c']))->toBe([':not(a, x)'])
            ->and($t->extendSelectorPartByTargets(':not(a, x)', ['a', 't2 t3', '> a'], ['c']))
            ->toBe([':not(a, c, x)'])
            ->and($t->extendSelectorPartByTargets(':not(a, x)', ['a'], ['b', '']))->toBe([':not(a, b, x)'])
            ->and($t->extendSelectorPartByTargets(':not(a, x)', ['a'], [':matches(s)', ':not(b)', '']))
            ->toBe([':not(a, s, x)'])
            ->and($t->extendSelectorPartByTargets(':not(a)', ['a'], ['b', '']))->toBe([':not(a):not(b)'])
            ->and($t->extendSelectorPartByTargets('a', ['a'], ['>']))->toBe(['a', '>']);
    });

    it('normalizeExtendPart(), namespaces and escape canonicalization', function () {
        $t = $this->tokenizer;

        expect($t->normalizeExtendPart(':nth-child(2n of )'))->toBe(':nth-child(2n of )')
            ->and($t->doesCompoundSatisfy('x|div', 'x|*'))->toBeTrue()
            ->and($t->doesCompoundSatisfy('x|div', '*|div'))->toBeTrue()
            ->and($t->unifyCompounds('a', 'x|a'))->toBeNull()
            ->and($t->unifyCompounds('x|a', '*'))->toBeNull()
            ->and($t->unifyCompounds('a', '*|a'))->toBe('a')
            ->and($t->canonicalizeSelectorEscapes('"a b"\\41 x'))->toBe('"a b"Ax')
            ->and($t->canonicalizeSelectorEscapes('"x"\\0a y'))->toBe('"x"\\a y')
            ->and($t->canonicalizeSelectorEscapes('#{\\41 a}'))->toBe('#{\\41 a}')
            ->and($t->canonicalizeSelectorEscapes("\\\na"))->toBe('a')
            ->and($t->canonicalizeSelectorEscapes("\\\r\na"))->toBe('a')
            ->and($t->canonicalizeSelectorEscapes("\\\ra"))->toBe('a')
            ->and($t->canonicalizeSelectorEscapes("a\u{00E9}\\"))->toBe("a\u{00E9}\\\\")
            ->and($t->canonicalizeSelectorEscapes('\e9 x'))->toBe('éx')
            ->and($t->canonicalizeSelectorEscapes('a[\\'))->toBe('a[\\');
    });

    it('findFullyInterpolatedNthIndices() and normalizeAnPlusB()', function () {
        $t = $this->tokenizer;

        expect($t->findFullyInterpolatedNthIndices(':nth-child(#{odd})'))->toBe([0])
            ->and($t->findFullyInterpolatedNthIndices(':nth-child(#{"a\\"b"})'))->toBe([0])
            ->and($t->findFullyInterpolatedNthIndices(':nth-child(#{{b)'))->toBe([])
            ->and($t->normalizeNthArguments('a:nth-child(-n)'))->toBe('a:nth-child(-n)')
            ->and($t->normalizeNthArguments('a:nth-child(2n x)'))->toBe('a:nth-child(2n x)')
            ->and($t->normalizeNthArguments('a:nth-child(5z)'))->toBe('a:nth-child(5z)')
            ->and($t->normalizeNthArguments('a:nth-child(abc)'))->toBe('a:nth-child(abc)')
            ->and($t->normalizeNthArguments('a:nth-child()'))->toBe('a:nth-child()')
            ->and($t->normalizeNthArguments('a:nth-child(2n+)'))->toBe('a:nth-child(2n+)')
            ->and($t->normalizeNthArguments('a:nth-child(-2 n + 3)'))->toBe('a:nth-child(-2 n + 3)')
            ->and($t->normalizeNthArguments('a:nth-child(ODD)'))->toBe('a:nth-child(ODD)')
            ->and($t->normalizeNthArguments('a:nth-child(2n+1 x)'))->toBe('a:nth-child(2n+1 x)')
            ->and($t->normalizeNthArguments('a:nth-child(+)'))->toBe('a:nth-child(+)')
            ->and($t->normalizeNthArguments('a:nth-child(2n)'))->toBe('a:nth-child(2n)')
            ->and($t->normalizeNthArguments('a:nth-child(+3)'))->toBe('a:nth-child(3)')
            ->and($t->normalizeNthArguments('a:nth-child(-0n+5)'))->toBe('a:nth-child(0n+5)')
            ->and($t->normalizeNthArguments('a:nth-last-child(1n+0)'))->toBe('a:nth-last-child(n)');
    });

    it('normalizeExtendPart() keeps nth argument shapes stable', function () {
        $t = $this->tokenizer;

        expect($t->normalizeExtendPart('a:nth-child(1 of "x")'))->toBe('a:nth-child(1 of "x")')
            ->and($t->normalizeExtendPart('a:nth-child(x( of )y)'))->toBe('a:nth-child(x( of )y)')
            ->and($t->normalizeExtendPart(':nth-child(x[ of ]z)'))->toBe(':nth-child(x[ of ]z)')
            ->and($t->normalizeExtendPart('a:nth-child(1 of)'))->toBe('a:nth-child(1 of)')
            ->and($t->normalizeExtendPart('b:nth-child("q" of s)'))->toBe('b:nth-child("q" of s)');
    });

    it('complexesAreSuperselector() evaluates strict pseudo arguments', function () {
        $t = $this->tokenizer;

        expect($t->complexesAreSuperselector($t->parseComplexComponents(':slotted(a)'), $t->parseComplexComponents(':slotted(x)')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':is(> a)'), $t->parseComplexComponents('b')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':hover'), $t->parseComplexComponents('div:is(a)')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':not(s)'), $t->parseComplexComponents('*')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':not(> a)'), $t->parseComplexComponents('b')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents('::slotted(a)'), $t->parseComplexComponents('::slot(z)')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':slotted(a)'), $t->parseComplexComponents('::slotted(x)')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':slotted(a)'), $t->parseComplexComponents('.x ::slotted(y)')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':is(b)'), $t->parseComplexComponents('.x:is(b)')))->toBeTrue()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents(':is(m) p'), $t->parseComplexComponents('x :is(m) y')))->toBeFalse();
    });

    it('complexesAreSuperselector() and weaveParents() walk bogus combinator spans', function () {
        $t = $this->tokenizer;

        expect($t->complexesAreSuperselector($t->parseComplexComponents('a'), $t->parseComplexComponents('x > > y > > z')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents('p a'), $t->parseComplexComponents('x > > y > z')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents('p a'), $t->parseComplexComponents('x > > y > > z')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents('m ~ n'), $t->parseComplexComponents('m ~ r > n')))->toBeFalse()
            ->and($t->complexesAreSuperselector($t->parseComplexComponents('w ~ q'), $t->parseComplexComponents('w > q')))->toBeFalse()
            ->and($t->weaveParents($t->parseComplexComponents('m#x > q2'), $t->parseComplexComponents('r#x > s t')))->toHaveCount(1)
            ->and($t->weaveParents($t->parseComplexComponents('a#x'), $t->parseComplexComponents('b#x y')))->toHaveCount(2);
    });
});
