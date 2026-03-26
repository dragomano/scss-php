<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\SelectorTokenizer;

describe('SelectorTokenizer', function () {
    it('tokenizeCompound() splits simple element selector', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->tokenizeCompound('div'))->toBe(['div']);
    });

    it('tokenizeCompound() splits class and element', function () {
        $tokenizer = new SelectorTokenizer();

        $tokens = $tokenizer->tokenizeCompound('div.foo');
        expect($tokens)->toContain('div')
            ->and($tokens)->toContain('.foo');
    });

    it('tokenizeCompound() handles id selector', function () {
        $tokenizer = new SelectorTokenizer();

        $tokens = $tokenizer->tokenizeCompound('#main');
        expect($tokens)->toContain('#main');
    });

    it('tokenizeCompound() handles pseudo-class', function () {
        $tokenizer = new SelectorTokenizer();

        $tokens = $tokenizer->tokenizeCompound('a:hover');
        expect($tokens)->toContain(':hover');
    });

    it('tokenizeCompound() handles pseudo-element', function () {
        $tokenizer = new SelectorTokenizer();

        $tokens = $tokenizer->tokenizeCompound('p::before');
        expect($tokens)->toContain('::before');
    });

    it('tokenizeCompound() handles attribute selector', function () {
        $tokenizer = new SelectorTokenizer();

        $tokens = $tokenizer->tokenizeCompound('[type="text"]');
        expect($tokens)->toContain('[type="text"]');
    });

    it('tokenizeCompound() handles universal selector', function () {
        $tokenizer = new SelectorTokenizer();

        $tokens = $tokenizer->tokenizeCompound('*');
        expect($tokens)->toContain('*');
    });

    it('doesCompoundSatisfy() returns true when candidate matches required', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->doesCompoundSatisfy('div.foo.bar', '.foo'))->toBeTrue();
    });

    it('doesCompoundSatisfy() returns false when candidate missing required token', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->doesCompoundSatisfy('div.bar', '.foo'))->toBeFalse();
    });

    it('doesCompoundSatisfy() returns true for empty required', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->doesCompoundSatisfy('div', ''))->toBeTrue();
    });

    it('unifyCompounds() merges two compatible selectors', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->unifyCompounds('.foo', '.bar');
        expect($result)->toContain('foo')
            ->and($result)->toContain('bar');
    });

    it('unifyCompounds() returns null for incompatible element types', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->unifyCompounds('div', 'span');
        expect($result)->toBeNull();
    });

    it('unifyCompounds() returns null for different ids', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->unifyCompounds('#foo', '#bar');
        expect($result)->toBeNull();
    });

    it('hasUnsupportedTopLevelCombinator() detects child combinator', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->hasUnsupportedTopLevelCombinator('div > span'))->toBeTrue();
    });

    it('hasUnsupportedTopLevelCombinator() detects adjacent sibling combinator', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->hasUnsupportedTopLevelCombinator('h1 + p'))->toBeTrue();
    });

    it('hasUnsupportedTopLevelCombinator() returns false for descendant selector', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->hasUnsupportedTopLevelCombinator('div span'))->toBeFalse();
    });

    it('hasUnsupportedTopLevelCombinator() ignores combinators inside parentheses', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->hasUnsupportedTopLevelCombinator(':is(a > b)'))->toBeFalse();
    });

    it('interleaveSequences() returns both orderings', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->interleaveSequences(['a', 'b'], ['c']);
        expect(count($result))->toBe(2);
    });

    it('interleaveSequences() returns single sequence when one side empty', function () {
        $tokenizer = new SelectorTokenizer();

        expect($tokenizer->interleaveSequences([], ['x']))->toBe([['x']])
            ->and($tokenizer->interleaveSequences(['y'], []))->toBe([['y']]);
    });

    it('orderTokens() puts ids first, then classes, then pseudos', function () {
        $tokenizer = new SelectorTokenizer();

        $ordered = $tokenizer->orderTokens(['.bar', '#foo', ':hover']);
        expect($ordered[0])->toBe('#foo')
            ->and($ordered[1])->toBe('.bar')
            ->and($ordered[2])->toBe(':hover');
    });

    it('extractTypeToken() returns element name', function () {
        $tokenizer = new SelectorTokenizer();
        $tokens = $tokenizer->tokenizeCompound('div.foo');

        expect($tokenizer->extractTypeToken($tokens))->toBe('div');
    });

    it('extractIdToken() returns id token', function () {
        $tokenizer = new SelectorTokenizer();
        $tokens = $tokenizer->tokenizeCompound('#main.foo');

        expect($tokenizer->extractIdToken($tokens))->toBe('#main');
    });

    it('removeTokensFromCompound() removes matching tokens', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->removeTokensFromCompound('div.foo.bar', ['.foo']);
        expect($result)->toBe('div.bar');
    });

    it('removeTokensFromCompound() returns null if target token not found', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->removeTokensFromCompound('div.bar', ['.foo']);
        expect($result)->toBeNull();
    });

    it('splitAtTopLevel() splits by comma at top level', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->splitAtTopLevel('a, b, c', [',']);
        expect($result)->toBe(['a', 'b', 'c']);
    });

    it('splitAtTopLevel() does not split inside parentheses', function () {
        $tokenizer = new SelectorTokenizer();

        $result = $tokenizer->splitAtTopLevel(':is(a, b), c', [',']);
        expect($result)->toBe([':is(a, b)', 'c']);
    });
});
