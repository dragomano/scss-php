<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Tests\RuntimeFactory;

describe('Text service', function () {
    beforeEach(function () {
        $this->text = RuntimeFactory::createRuntime()->text();
        $this->env  = new Environment();
    });

    describe('interpolateText()', function () {
        it('replaces #{$var} with its value', function () {
            $this->env->getCurrentScope()->setVariable('name', new StringNode('button'));

            expect($this->text->interpolateText('.#{$name}', $this->env))->toBe('.button');
        });

        it('returns text unchanged when no interpolation present', function () {
            expect($this->text->interpolateText('.plain', $this->env))->toBe('.plain');
        });

        it('handles multiple interpolations in one string', function () {
            $this->env->getCurrentScope()->setVariable('a', new StringNode('x'));
            $this->env->getCurrentScope()->setVariable('b', new StringNode('y'));

            expect($this->text->interpolateText('#{$a}-#{$b}', $this->env))->toBe('x-y');
        });
    });

    describe('replaceVariableReferencesInText()', function () {
        it('replaces $var references with formatted value', function () {
            $this->env->getCurrentScope()->setVariable('gap', new NumberNode(12, 'px'));

            expect($this->text->replaceVariableReferencesInText('gap: $gap', $this->env))->toBe('gap: 12px');
        });

        it('leaves text unchanged when no $ present', function () {
            expect($this->text->replaceVariableReferencesInText('no vars here', $this->env))->toBe('no vars here');
        });

        it('leaves lone $ unchanged when not a valid variable name', function () {
            expect($this->text->replaceVariableReferencesInText('price: $', $this->env))->toBe('price: $');
        });
    });

    describe('splitTopLevelByOperator()', function () {
        it('splits on top-level "and"', function () {
            expect($this->text->splitTopLevelByOperator('(a or b) and c', 'and'))
                ->toBe(['(a or b)', 'c']);
        });

        it('splits on top-level "or"', function () {
            expect($this->text->splitTopLevelByOperator('a or b or c', 'or'))
                ->toBe(['a', 'b', 'c']);
        });

        it('does not split inside parentheses', function () {
            expect($this->text->splitTopLevelByOperator('(a and b)', 'and'))
                ->toBe(['(a and b)']);
        });

        it('returns single-element array when operator not found', function () {
            expect($this->text->splitTopLevelByOperator('(color: red)', 'and'))
                ->toBe(['(color: red)']);
        });
    });

    describe('isWrappedBySingleOuterParentheses()', function () {
        it('returns true for simple parenthesized expression', function () {
            expect($this->text->isWrappedBySingleOuterParentheses('(a and b)'))->toBeTrue();
        });

        it('returns false for two separate parenthesized groups', function () {
            expect($this->text->isWrappedBySingleOuterParentheses('(a) and (b)'))->toBeFalse();
        });

        it('returns false for string without outer parens', function () {
            expect($this->text->isWrappedBySingleOuterParentheses('a and b'))->toBeFalse();
        });

        it('returns false for empty string', function () {
            expect($this->text->isWrappedBySingleOuterParentheses(''))->toBeFalse();
        });
    });

    describe('parseColonSeparatedPair()', function () {
        it('parses simple name: value pair', function () {
            $result = $this->text->parseColonSeparatedPair('color: red');

            expect($result)->toBe(['name' => 'color', 'value' => 'red']);
        });

        it('trims surrounding whitespace', function () {
            $result = $this->text->parseColonSeparatedPair('  display : flex  ');

            expect($result['name'])->toBe('display')
                ->and($result['value'])->toBe('flex');
        });

        it('returns null for string without colon', function () {
            expect($this->text->parseColonSeparatedPair('no colon here'))->toBeNull();
        });

        it('returns null for empty string', function () {
            expect($this->text->parseColonSeparatedPair(''))->toBeNull();
        });

        it('handles value with colon (takes first colon only)', function () {
            $result = $this->text->parseColonSeparatedPair('background: url(x:y)');

            expect($result['name'])->toBe('background')
                ->and($result['value'])->toBe('url(x:y)');
        });
    });
});
