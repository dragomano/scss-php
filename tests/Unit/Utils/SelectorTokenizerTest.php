<?php

declare(strict_types=1);

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
        expect($tokens)->toContain('[type="text"]');
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

    it('unifyCompounds() returns empty string when only universal selectors remain', function () {
        expect($this->tokenizer->unifyCompounds('*', '*'))->toBe('');
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

    it('hasBogusTopLevelCombinatorSequence() ignores nested combinator sequences', function () {
        expect($this->tokenizer->hasBogusTopLevelCombinatorSequence(':is(div > + span) a'))->toBeFalse();
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

    it('orderTokens() places pseudo-elements before other tokens', function () {
        expect($this->tokenizer->orderTokens(['div', '::before']))->toBe(['::before', 'div']);
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

    it('replaceExtendTargetInStructuredSelector() returns empty array when input is incomplete', function () {
        expect($this->tokenizer->replaceExtendTargetInStructuredSelector([], ['.foo'], ['.bar']))->toBe([])
            ->and($this->tokenizer->replaceExtendTargetInStructuredSelector(['div.foo'], ['.foo'], []))->toBe([]);
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
});
