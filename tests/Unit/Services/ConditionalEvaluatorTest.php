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
use Tests\RuntimeFactory;

describe('ConditionalEvaluator', function () {
    beforeEach(function () {
        $runtime = RuntimeFactory::createRuntime();

        $this->env = new Environment();

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

        $result = $this->evaluator->evaluateInlineIfFunction('if', [
            $condition,
            new StringNode('yes'),
            new StringNode('no'),
        ], $this->env);

        expect($result)->toBeInstanceOf(StringNode::class)
            ->and($result->value)->toBe('yes');
    });

    it('returns css expressions for unquoted inline if strings and functions', function () {
        $plainCondition    = new StringNode('condition');
        $booleanCondition  = new StringNode('boolean-condition');
        $functionCondition = new StringNode('function-condition');
        $formattedFunction = new FunctionNode('calc', [new StringNode('1px')]);

        $this->evaluatedValues[spl_object_id($plainCondition)] = new StringNode('screen and (color)');
        $this->evaluatedValues[spl_object_id($booleanCondition)] = new StringNode('1 >= 0');
        $this->evaluatedValues[spl_object_id($functionCondition)] = $formattedFunction;

        $plainResult = $this->evaluator->evaluateInlineIfFunction('if', [
            $plainCondition,
            new StringNode('yes'),
            new StringNode('no'),
        ], $this->env);

        $booleanResult = $this->evaluator->evaluateInlineIfFunction('if', [
            $booleanCondition,
            new StringNode('yes'),
            new StringNode('no'),
        ], $this->env);

        $functionResult = $this->evaluator->evaluateInlineIfFunction('if', [
            $functionCondition,
            new StringNode('yes'),
            new StringNode('no'),
        ], $this->env);

        expect($plainResult)->toBeInstanceOf(StringNode::class)
            ->and($plainResult->value)->toBe('if(screen and (color): yes; else: no)')
            ->and($booleanResult)->toBeInstanceOf(StringNode::class)
            ->and($booleanResult->value)->toBe('yes')
            ->and($functionResult)->toBeInstanceOf(StringNode::class)
            ->and($functionResult->value)->toBe('if(calc(1px): yes; else: no)');
    });

    it('falls back to truthiness for non-string inline if conditions', function () {
        $condition = new StringNode('condition');

        $this->evaluatedValues[spl_object_id($condition)] = new NumberNode(1);

        $result = $this->evaluator->evaluateInlineIfFunction('if', [
            $condition,
            new StringNode('yes'),
            new StringNode('no'),
        ], $this->env);

        expect($result)->toBeInstanceOf(StringNode::class)
            ->and($result->value)->toBe('yes');
    });

    it('recognizes likely sass boolean condition strings', function () {
        $this->env->getCurrentScope()->setVariableLocal('flag', new BooleanNode(false));

        $empty = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new StringNode(''),
                new StringNode('yes'),
                new StringNode('no'),
            ],
            $this->env,
        );

        $variable = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new StringNode('$flag'),
                new StringNode('yes'),
                new StringNode('no'),
            ],
            $this->env,
        );

        $negated = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new StringNode('not $flag'),
                new StringNode('yes'),
                new StringNode('no'),
            ],
            $this->env,
        );

        $comparison = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new StringNode('1 >= 0'),
                new StringNode('yes'),
                new StringNode('no'),
            ],
            $this->env,
        );

        $css = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new StringNode('screen'), new StringNode('yes'), new StringNode('no'),
            ],
            $this->env,
        );

        expect($empty)->toBeInstanceOf(StringNode::class)
            ->and($empty->value)->toBe('if(: yes; else: no)')
            ->and($variable)->toBeInstanceOf(StringNode::class)
            ->and($variable->value)->toBe('no')
            ->and($negated)->toBeInstanceOf(StringNode::class)
            ->and($negated->value)->toBe('yes')
            ->and($comparison)->toBeInstanceOf(StringNode::class)
            ->and($comparison->value)->toBe('yes')
            ->and($css)->toBeInstanceOf(StringNode::class)
            ->and($css->value)->toBe('if(screen: yes; else: no)');
    });

    it('collapses url string concatenation with quotes escapes and empty segments', function () {
        $concatArg         = new NumberNode(10);
        $escapedQuoteArg   = new NumberNode(11);
        $escapedSlashArg   = new NumberNode(12);
        $plainFormattedArg = new NumberNode(13);
        $emptyCombinedArg  = new NumberNode(14);

        $this->formattedValues[spl_object_id($concatArg)] = '"foo" + "bar"';
        $this->formattedValues[spl_object_id($escapedQuoteArg)] = '"a\\"b" + "c"';
        $this->formattedValues[spl_object_id($escapedSlashArg)] = '"a\\\\b" + "c"';
        $this->formattedValues[spl_object_id($plainFormattedArg)] = ' plain-value ';
        $this->formattedValues[spl_object_id($emptyCombinedArg)] = ' + ';

        $concat         = $this->evaluator->evaluateSpecialUrlFunction('url', [$concatArg], $this->env);
        $escapedQuote   = $this->evaluator->evaluateSpecialUrlFunction('url', [$escapedQuoteArg], $this->env);
        $escapedSlash   = $this->evaluator->evaluateSpecialUrlFunction('url', [$escapedSlashArg], $this->env);
        $plainFormatted = $this->evaluator->evaluateSpecialUrlFunction('url', [$plainFormattedArg], $this->env);
        $emptyCombined  = $this->evaluator->evaluateSpecialUrlFunction('url', [$emptyCombinedArg], $this->env);
        $plain          = $this->evaluator->evaluateSpecialUrlFunction('url', [new StringNode(' plain-value ')], $this->env);
        $onlyOperator   = $this->evaluator->evaluateSpecialUrlFunction('url', [new StringNode(' + ')], $this->env);

        expect($concat)->toBeInstanceOf(FunctionNode::class)
            ->and($concat->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($concat->arguments[0]->value)->toBe('foobar')
            ->and($concat->arguments[0]->quoted)->toBeTrue()
            ->and($escapedQuote->arguments[0]->value)->toBe('a\\"bc')
            ->and($escapedQuote->arguments[0]->quoted)->toBeTrue()
            ->and($escapedSlash->arguments[0]->value)->toBe('a\\\\bc')
            ->and($escapedSlash->arguments[0]->quoted)->toBeTrue()
            ->and($plainFormatted->arguments[0]->value)->toBe('plain-value')
            ->and($plainFormatted->arguments[0]->quoted)->toBeFalse()
            ->and($emptyCombined->arguments[0]->value)->toBe('+')
            ->and($emptyCombined->arguments[0]->quoted)->toBeFalse()
            ->and($plain->arguments[0]->value)->toBe(' plain-value ')
            ->and($plain->arguments[0]->quoted)->toBeFalse()
            ->and($onlyOperator->arguments[0]->value)->toBe(' + ')
            ->and($onlyOperator->arguments[0]->quoted)->toBeFalse();
    });

    it('combines inline if list conditions for or and and css expressions', function () {
        $orResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('screen'),
            new StringNode('or'),
            new StringNode('print'),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $andResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('screen'),
            new StringNode('and'),
            new StringNode('print'),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        expect($orResult)->toBeInstanceOf(StringNode::class)
            ->and($orResult->value)->toBe('if(screen or print: yes; else: no)')
            ->and($andResult)->toBeInstanceOf(StringNode::class)
            ->and($andResult->value)->toBe('if(screen and print: yes; else: no)');
    });

    it('collapses inline if logical lists to boolean results when css parts are absent', function () {
        $orResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new BooleanNode(false),
            new StringNode('or'),
            new BooleanNode(false),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $andFalseResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new BooleanNode(true),
            new StringNode('and'),
            new BooleanNode(false),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $andTrueResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new BooleanNode(true),
            new StringNode('and'),
            new BooleanNode(true),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        expect($orResult)->toBeInstanceOf(StringNode::class)
            ->and($orResult->value)->toBe('no')
            ->and($andFalseResult->value)->toBe('no')
            ->and($andTrueResult->value)->toBe('yes');
    });

    it('handles not inline if list conditions for empty boolean and css expressions', function () {
        $emptyResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('not'),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $boolResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('not'),
            new BooleanNode(true),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $cssResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('not'),
            new StringNode('screen and print'),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $recursiveResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('not'),
            new BooleanNode(false),
            new StringNode('or'),
            new BooleanNode(false),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $multiItemResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('not'),
            new StringNode('screen'),
            new StringNode('print'),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        expect($emptyResult)->toBeInstanceOf(StringNode::class)
            ->and($emptyResult->value)->toBe('no')
            ->and($boolResult->value)->toBe('no')
            ->and($cssResult->value)->toBe('if(not (screen and print): yes; else: no)')
            ->and($recursiveResult->value)->toBe('yes')
            ->and($multiItemResult->value)->toBe('if(not screen print: yes; else: no)');
    });

    it('evaluates inline if list comparisons and fallbacks', function () {
        $comparisonResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new NumberNode(2),
            new StringNode('>'),
            new NumberNode(1),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $singleResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new BooleanNode(true),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        $fallbackResult = $this->evaluator->evaluateInlineIfFunction('if', [new ListNode([
            new StringNode('screen'),
            new StringNode('print'),
        ], 'space'), new StringNode('yes'), new StringNode('no')], $this->env);

        expect($comparisonResult)->toBeInstanceOf(StringNode::class)
            ->and($comparisonResult->value)->toBe('yes')
            ->and($singleResult->value)->toBe('yes')
            ->and($fallbackResult->value)->toBe('if(screen print: yes; else: no)');
    });

    it('evaluates inline if list comparisons only for supported operators', function () {
        $single = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new ListNode([new NumberNode(1)], 'space'),
                new StringNode('yes'),
                new StringNode('no'),
            ],
            $this->env,
        );

        $unsupported = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new ListNode([
                    new NumberNode(1),
                    new StringNode('~='),
                    new NumberNode(1),
                ], 'space'),
                new StringNode('yes'),
                new StringNode('no'),
            ],
            $this->env,
        );

        $supported = $this->evaluator->evaluateInlineIfFunction(
            'if',
            [
                new ListNode([
                    new NumberNode(2),
                    new StringNode('>='),
                    new NumberNode(1),
                ], 'space'),
                new StringNode('yes'),
                new StringNode('no'),
            ],
            $this->env,
        );

        expect($single)->toBeInstanceOf(StringNode::class)
            ->and($single->value)->toBe('yes')
            ->and($unsupported->value)->toBe('if(1 ~= 1: yes; else: no)')
            ->and($supported->value)->toBe('yes');
    });

    it('returns null from logical item evaluation when comparison resolution fails', function () {
        $this->comparisonListResults['alpha beta'] = null;

        $orResult = $this->evaluator->evaluateLogicalList(new ListNode([
            new StringNode('alpha'),
            new StringNode('beta'),
            new StringNode('or'),
            new BooleanNode(true),
        ], 'space'), $this->env);

        $andResult = $this->evaluator->evaluateLogicalList(new ListNode([
            new BooleanNode(true),
            new StringNode('and'),
            new StringNode('alpha'),
            new StringNode('beta'),
        ], 'space'), $this->env);

        expect($orResult)->toBeNull()
            ->and($andResult)->toBeNull();
    });

    it('handles logical not evaluation for empty and unresolved operands', function () {
        $this->comparisonListResults['alpha beta'] = null;

        $emptyResult = $this->evaluator->evaluateLogicalList(new ListNode([
            new StringNode('not'),
        ], 'space'), $this->env);

        $nullResult = $this->evaluator->evaluateLogicalList(new ListNode([
            new StringNode('not'),
            new StringNode('alpha'),
            new StringNode('beta'),
        ], 'space'), $this->env);

        expect($emptyResult)->toBeInstanceOf(BooleanNode::class)
            ->and($emptyResult->value)->toBeFalse()
            ->and($nullResult)->toBeNull();
    });
});
