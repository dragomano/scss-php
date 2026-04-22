<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\AstValueEvaluatorInterface;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\ComparisonListEvaluatorInterface;
use Bugo\SCSS\Services\ConditionalEvaluator;
use Bugo\SCSS\Values\ValueFactory;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

describe('ConditionalEvaluator', function () {
    beforeEach(function () {
        $runtime = RuntimeFactory::createRuntime();

        $this->env                   = new Environment();
        $this->formattedValues       = [];
        $this->evaluatedValues       = [];
        $this->comparisonListResults = [];

        $format = null;
        $format = function (AstNode $node, Environment $env) use (&$format): string {
            $id = spl_object_id($node);

            if (isset($this->formattedValues[$id])) {
                return $this->formattedValues[$id];
            }

            return match (true) {
                $node instanceof StringNode,
                $node instanceof NumberNode,
                $node instanceof BooleanNode  => (string) $node,
                $node instanceof NullNode     => 'null',
                $node instanceof FunctionNode => $node->name . '(' . implode(', ', array_map(
                    fn(AstNode $argument): string => $format($argument, $env),
                    $node->arguments,
                )) . ')',
                $node instanceof ListNode => implode(
                    $node->separator === 'comma' ? ', ' : ' ',
                    array_map(
                        fn(AstNode $item): string => $format($item, $env),
                        $node->items,
                    ),
                ),
                default => 'node',
            };
        };

        $this->evaluator = new ConditionalEvaluator(
            $runtime->condition(),
            $runtime->text(),
            new class ($this) implements AstValueEvaluatorInterface {
                public function __construct(private readonly object $testCase) {}

                public function evaluate(AstNode $node, Environment $env): AstNode
                {
                    return $this->testCase->evaluatedValues[spl_object_id($node)] ?? $node;
                }
            },
            new class ($format) implements AstValueFormatterInterface {
                public function __construct(private readonly Closure $format) {}

                public function format(AstNode $node, Environment $env): string
                {
                    return ($this->format)($node, $env);
                }
            },
            new class ($this, $format) implements ComparisonListEvaluatorInterface {
                public function __construct(
                    private readonly object  $testCase,
                    private readonly Closure $format,
                ) {}

                public function evaluate(ListNode $list, Environment $env): ?AstNode
                {
                    return $this->testCase->comparisonListResults[
                        ($this->format)($list, $env)
                    ] ?? null;
                }
            },
            new ValueFactory(),
        );
        $this->accessor = new ReflectionAccessor($this->evaluator);
    });

    it('evaluates special url() arguments using formatted concatenated values', function () {
        $quotedArgument = new NumberNode(1);
        $plainArgument  = new NumberNode(2);

        $this->formattedValues[spl_object_id($quotedArgument)] = '"foo" + "bar"';
        $this->formattedValues[spl_object_id($plainArgument)]  = 'foo + bar';

        $quotedResult = $this->evaluator->evaluateSpecialUrlFunction('url', [$quotedArgument], $this->env);
        $plainResult  = $this->evaluator->evaluateSpecialUrlFunction('url', [$plainArgument], $this->env);

        expect($quotedResult)->toBeInstanceOf(FunctionNode::class)
            ->and($quotedResult->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($quotedResult->arguments[0]->value)->toBe('foobar')
            ->and($quotedResult->arguments[0]->quoted)->toBeTrue()
            ->and($plainResult)->toBeInstanceOf(FunctionNode::class)
            ->and($plainResult->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($plainResult->arguments[0]->value)->toBe('foobar')
            ->and($plainResult->arguments[0]->quoted)->toBeFalse();
    });

    it('detects inline if list conditions when a space list is evaluated', function () {
        $condition = new StringNode('condition');
        $this->evaluatedValues[spl_object_id($condition)] = new ListNode([
            new BooleanNode(false),
            new StringNode('or'),
            new BooleanNode(true),
        ], 'space');

        $result = $this->accessor->callMethod('evaluateInlineIfCondition', [$condition, $this->env]);

        expect($result)->toBe(['kind' => 'bool', 'value' => true]);
    });

    it('returns css expressions for unquoted inline if strings and functions', function () {
        $plainCondition    = new StringNode('condition');
        $booleanCondition  = new StringNode('boolean-condition');
        $functionCondition = new StringNode('function-condition');
        $formattedFunction = new FunctionNode('calc', [new StringNode('1px')]);

        $this->evaluatedValues[spl_object_id($plainCondition)] = new StringNode('screen and (color)');
        $this->evaluatedValues[spl_object_id($booleanCondition)] = new StringNode('1 >= 0');
        $this->evaluatedValues[spl_object_id($functionCondition)] = $formattedFunction;

        $plainResult = $this->accessor->callMethod('evaluateInlineIfCondition', [$plainCondition, $this->env]);
        $booleanResult = $this->accessor->callMethod('evaluateInlineIfCondition', [$booleanCondition, $this->env]);
        $functionResult = $this->accessor->callMethod('evaluateInlineIfCondition', [$functionCondition, $this->env]);

        expect($plainResult)->toBe(['kind' => 'css', 'expression' => 'screen and (color)'])
            ->and($booleanResult)->toBe(['kind' => 'bool', 'value' => true])
            ->and($functionResult)->toBe(['kind' => 'css', 'expression' => 'calc(1px)']);
    });

    it('falls back to truthiness for non-string inline if conditions', function () {
        $condition = new StringNode('condition');
        $this->evaluatedValues[spl_object_id($condition)] = new NumberNode(1);

        $result = $this->accessor->callMethod('evaluateInlineIfCondition', [$condition, $this->env]);

        expect($result)->toBe(['kind' => 'bool', 'value' => true]);
    });

    it('recognizes likely sass boolean condition strings', function () {
        expect($this->accessor->callMethod('isLikelySassBooleanCondition', ['']))->toBeFalse()
            ->and($this->accessor->callMethod('isLikelySassBooleanCondition', ['$flag']))->toBeTrue()
            ->and($this->accessor->callMethod('isLikelySassBooleanCondition', ['not $flag']))->toBeTrue()
            ->and($this->accessor->callMethod('isLikelySassBooleanCondition', ['1 >= 0']))->toBeTrue()
            ->and($this->accessor->callMethod('isLikelySassBooleanCondition', ['screen']))->toBeFalse();
    });

    it('collapses url string concatenation with quotes escapes and empty segments', function () {
        expect($this->accessor->callMethod('collapseUrlStringConcatenation', ['"foo" + "bar"']))->toBe('"foobar"')
            ->and($this->accessor->callMethod('collapseUrlStringConcatenation', ['"a\\"b" + "c"']))->toBe('"a\\"bc"')
            ->and($this->accessor->callMethod('collapseUrlStringConcatenation', ['"a\\\\b" + "c"']))->toBe('"a\\\\bc"')
            ->and($this->accessor->callMethod('collapseUrlStringConcatenation', [' plain-value ']))->toBe('plain-value')
            ->and($this->accessor->callMethod('collapseUrlStringConcatenation', [' + ']))->toBe('+');
    });

    it('combines inline if list conditions for or and and css expressions', function () {
        $orResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('screen'),
            new StringNode('or'),
            new StringNode('print'),
        ], $this->env]);

        $andResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('screen'),
            new StringNode('and'),
            new StringNode('print'),
        ], $this->env]);

        expect($orResult)->toBe(['kind' => 'css', 'expression' => 'screen or print'])
            ->and($andResult)->toBe(['kind' => 'css', 'expression' => 'screen and print']);
    });

    it('collapses inline if logical lists to boolean results when css parts are absent', function () {
        $orResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new BooleanNode(false),
            new StringNode('or'),
            new BooleanNode(false),
        ], $this->env]);

        $andFalseResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new BooleanNode(true),
            new StringNode('and'),
            new BooleanNode(false),
        ], $this->env]);

        $andTrueResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new BooleanNode(true),
            new StringNode('and'),
            new BooleanNode(true),
        ], $this->env]);

        expect($orResult)->toBe(['kind' => 'bool', 'value' => false])
            ->and($andFalseResult)->toBe(['kind' => 'bool', 'value' => false])
            ->and($andTrueResult)->toBe(['kind' => 'bool', 'value' => true]);
    });

    it('handles not inline if list conditions for empty boolean and css expressions', function () {
        $emptyResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('not'),
        ], $this->env]);

        $boolResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('not'),
            new BooleanNode(true),
        ], $this->env]);

        $cssResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('not'),
            new StringNode('screen and print'),
        ], $this->env]);

        $recursiveResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('not'),
            new BooleanNode(false),
            new StringNode('or'),
            new BooleanNode(false),
        ], $this->env]);

        $multiItemResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('not'),
            new StringNode('screen'),
            new StringNode('print'),
        ], $this->env]);

        expect($emptyResult)->toBe(['kind' => 'bool', 'value' => false])
            ->and($boolResult)->toBe(['kind' => 'bool', 'value' => false])
            ->and($cssResult)->toBe(['kind' => 'css', 'expression' => 'not (screen and print)'])
            ->and($recursiveResult)->toBe(['kind' => 'bool', 'value' => true])
            ->and($multiItemResult)->toBe(['kind' => 'css', 'expression' => 'not screen print']);
    });

    it('evaluates inline if list comparisons and fallbacks', function () {
        $comparisonResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new NumberNode(2),
            new StringNode('>'),
            new NumberNode(1),
        ], $this->env]);

        $singleResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new BooleanNode(true),
        ], $this->env]);

        $fallbackResult = $this->accessor->callMethod('evaluateInlineIfListCondition', [[
            new StringNode('screen'),
            new StringNode('print'),
        ], $this->env]);

        expect($comparisonResult)->toBe(['kind' => 'bool', 'value' => true])
            ->and($singleResult)->toBe(['kind' => 'bool', 'value' => true])
            ->and($fallbackResult)->toBe(['kind' => 'css', 'expression' => 'screen print']);
    });

    it('evaluates inline if list comparisons only for supported operators', function () {
        expect($this->accessor->callMethod('evaluateInlineIfListComparison', [[new NumberNode(1)], $this->env]))
            ->toBeNull()
            ->and($this->accessor->callMethod('evaluateInlineIfListComparison', [[
                new NumberNode(1),
                new StringNode('~='),
                new NumberNode(1),
            ], $this->env]))->toBeNull()
            ->and($this->accessor->callMethod('evaluateInlineIfListComparison', [[
                new NumberNode(2),
                new StringNode('>='),
                new NumberNode(1),
            ], $this->env]))->toBeTrue();
    });

    it('returns null from logical item evaluation when comparison resolution fails', function () {
        $this->comparisonListResults['alpha beta'] = null;

        $orResult = $this->accessor->callMethod('evaluateLogicalItems', [[
            new StringNode('alpha'),
            new StringNode('beta'),
            new StringNode('or'),
            new BooleanNode(true),
        ], $this->env]);

        $andResult = $this->accessor->callMethod('evaluateLogicalItems', [[
            new BooleanNode(true),
            new StringNode('and'),
            new StringNode('alpha'),
            new StringNode('beta'),
        ], $this->env]);

        expect($orResult)->toBeNull()
            ->and($andResult)->toBeNull();
    });

    it('handles logical not evaluation for empty and unresolved operands', function () {
        $this->comparisonListResults['alpha beta'] = null;

        $emptyResult = $this->accessor->callMethod('evaluateLogicalItems', [[
            new StringNode('not'),
        ], $this->env]);

        $nullResult = $this->accessor->callMethod('evaluateLogicalItems', [[
            new StringNode('not'),
            new StringNode('alpha'),
            new StringNode('beta'),
        ], $this->env]);

        expect($emptyResult)->toBeInstanceOf(BooleanNode::class)
            ->and($emptyResult->value)->toBeFalse()
            ->and($nullResult)->toBeNull();
    });
});
