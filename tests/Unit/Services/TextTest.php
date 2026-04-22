<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\AstValueEvaluatorInterface;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\Text;
use Tests\ReflectionAccessor;
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
        beforeEach(function () {
            $this->accessor = new ReflectionAccessor($this->text);
        });

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

        it('returns empty string for empty interpolation expressions', function () {
            $result = $this->text->interpolateText('#{}', $this->env);

            expect($result)->toBe('');
        });

        it('returns original interpolation expression when parser does not yield a declaration', function () {
            $result = $this->text->interpolateText('#{1 + 2}', $this->env);

            expect($result)->toBe('3');
        });

        it('returns interpolation expression as is when parser root does not contain a rule', function () {
            $text = new Text(
                new class implements ParserInterface {
                    public function setTrackSourceLocations(bool $track): void {}

                    public function parse(string $source): RootNode
                    {
                        return new RootNode([new StringNode('ignored')]);
                    }
                },
                new class implements AstValueEvaluatorInterface {
                    public function evaluate(Bugo\SCSS\Nodes\AstNode $node, Environment $env): Bugo\SCSS\Nodes\AstNode
                    {
                        return new StringNode('unused');
                    }
                },
                new class implements AstValueFormatterInterface {
                    public function format(Bugo\SCSS\Nodes\AstNode $node, Environment $env): string
                    {
                        return 'unused';
                    }
                },
            );
            $accessor = new ReflectionAccessor($text);

            expect($accessor->callMethod('resolveInterpolationExpression', ['literal-token', $this->env]))
                ->toBe('literal-token');
        });

        it('formats slash-separated bracketed interpolation lists', function () {
            $result = $this->accessor->callMethod('formatInterpolationValue', [
                new Bugo\SCSS\Nodes\ListNode(
                    [new StringNode('alpha'), new StringNode('beta')],
                    'slash',
                    true,
                ),
                $this->env,
            ]);

            expect($result)->toBe('[alpha / beta]');
        });

        it('formats space-separated interpolation lists with the default separator', function () {
            $result = $this->accessor->callMethod('formatInterpolationValue', [
                new Bugo\SCSS\Nodes\ListNode(
                    [new StringNode('alpha'), new StringNode('beta')],
                    'space',
                ),
                $this->env,
            ]);

            expect($result)->toBe('alpha beta');
        });

        it('keeps plus concatenation unchanged when the right side is not a variable name token', function () {
            $result = $this->accessor->callMethod('collapsePlusConcatenation', ['foo + !bar']);

            expect($result)->toBe('foo + !bar');
        });

        it('rejects empty variable names', function () {
            expect($this->accessor->callMethod('isVariableName', ['']))->toBeFalse();
        });

        it('rejects variable names with unsupported characters', function () {
            expect($this->accessor->callMethod('isVariableName', ['foo!']))->toBeFalse();
        });
    });
});
