<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\AstValueEvaluatorInterface;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\Text;
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

        it('formats slash-separated lists in interpolations', function () {
            $this->env->getCurrentScope()->setVariable(
                'list',
                new ListNode([new NumberNode(1), new NumberNode(2)], 'slash'),
            );

            expect($this->text->interpolateText('#{$list}', $this->env))->toBe('1 / 2');
        });

        it('formats bracketed space-separated lists in interpolations', function () {
            $this->env->getCurrentScope()->setVariable(
                'list',
                new ListNode([new StringNode('alpha'), new StringNode('beta')], 'space', true),
            );

            expect($this->text->interpolateText('#{$list}', $this->env))->toBe('[alpha beta]');
        });

        it('keeps trailing text when interpolation is not closed', function () {
            expect($this->text->replaceInterpolations('a#{b', $this->env))->toBe('a#{b');
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

    describe('internal helpers', function () {
        it('returns empty typed items when input is not an array', function () {
            expect($this->text->extractStringKeyedArrayItems('nope'))->toBe([]);
        });

        it('skips non-array items when extracting typed array items', function () {
            $items = $this->text->extractStringKeyedArrayItems([
                ['type' => 'atom', 'value' => 'display: grid'],
                'skip-me',
                123,
                ['type' => 'atom', 'value' => 'color: red'],
            ]);

            expect($items)->toBe([
                ['type' => 'atom', 'value' => 'display: grid'],
                ['type' => 'atom', 'value' => 'color: red'],
            ]);
        });

        it('keeps the rest of supports condition when closing parenthesis is missing', function () {
            $result = $this->text->resolveSupportsCondition('display and (color: red', $this->env);

            expect($result)->toBe('display and (color: red');
        });

        it('wraps not children in parentheses when rendering boolean supports expressions', function () {
            $result = $this->text->resolveSupportsCondition('(not (display: grid)) and (color: red)', $this->env);

            expect($result)->toBe('(not (display: grid)) and (color: red)');
        });

        it('adds parentheses around unwrapped not supports expressions inside boolean groups', function () {
            $result = $this->text->resolveSupportsCondition('not display and (color: red)', $this->env);

            expect($result)->toBe('(not display) and (color: red)');
        });

        it('adds parentheses around nested and supports expressions inside or groups', function () {
            $result = $this->text->resolveSupportsCondition('a or b and c', $this->env);

            expect($result)->toBe('a or (b and c)');
        });

        it('keeps plus concatenation unchanged when plus is not followed by a variable token', function () {
            $result = $this->text->resolveSupportsCondition('display + (color: red)', $this->env);

            expect($result)->toBe('display + (color: red)');
        });

        it('returns empty string for empty interpolation expressions', function () {
            $result = $this->text->interpolateText('#{}', $this->env);

            expect($result)->toBe('');
        });

        it('returns original interpolation expression when parser does not yield a declaration', function () {
            $result = $this->text->interpolateText('#{@if true {a:b}}', $this->env);

            expect($result)->toBe('@if true {a:b}');
        });

        it('returns original interpolation expression when parser does not yield a rule node', function () {
            $text = new Text(
                new class implements ParserInterface {
                    public function setTrackSourceLocations(bool $track): void {}

                    public function parse(string $source): RootNode
                    {
                        return new RootNode([new StringNode('not-a-rule')]);
                    }
                },
                new class implements AstValueEvaluatorInterface {
                    public function evaluate(AstNode $node, Environment $env): AstNode
                    {
                        return $node;
                    }
                },
                new class implements AstValueFormatterInterface {
                    public function format(AstNode $node, Environment $env): string
                    {
                        return '';
                    }
                },
            );

            expect($text->interpolateText('#{plain-token}', $this->env))->toBe('plain-token');
        });

        it('throws for interpolations with an empty variable name', function () {
            expect(fn() => $this->text->interpolateText('#{$}', $this->env))
                ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $');
        });

        it('throws for interpolations whose variable token contains invalid characters', function () {
            expect(fn() => $this->text->interpolateText('#{$foo!}', $this->env))
                ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $foo');
        });

        it('still evaluates arithmetic interpolations', function () {
            $result = $this->text->interpolateText('#{1 + 2}', $this->env);

            expect($result)->toBe('3');
        });
    });
});
