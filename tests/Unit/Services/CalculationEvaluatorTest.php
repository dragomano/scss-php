<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\ArithmeticListEvaluatorInterface;
use Bugo\SCSS\Services\AstToSassValueConverterInterface;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\CalculationEvaluator;
use Bugo\SCSS\Values\SassString;
use Bugo\SCSS\Values\SassValue;

function createCalculationEvaluator(?Closure $evaluateArithmetic = null): CalculationEvaluator
{
    $format = null;

    $format = static function (AstNode $node, Environment $env) use (&$format): string {
        if ($node instanceof NumberNode) {
            return (string) $node->value . ($node->unit ?? '');
        }

        if ($node instanceof StringNode) {
            return $node->value;
        }

        if ($node instanceof NamedArgumentNode) {
            return $node->name . ': ' . $format($node->value, $env);
        }

        if ($node instanceof FunctionNode) {
            $arguments = array_map(
                static fn(AstNode $argument): string => $format($argument, $env),
                $node->arguments,
            );

            return $node->name . '(' . implode(', ', $arguments) . ')';
        }

        if ($node instanceof ListNode || $node instanceof ArgumentListNode) {
            $separator = $node->separator === 'comma' ? ', ' : ' ';

            return implode($separator, array_map(
                static fn(AstNode $item): string => $format($item, $env),
                $node->items,
            ));
        }

        return '';
    };

    return new CalculationEvaluator(
        new class ($format) implements AstValueFormatterInterface {
            public function __construct(private Closure $format) {}

            public function format(AstNode $node, Environment $env): string
            {
                return ($this->format)($node, $env);
            }
        },
        new class ($evaluateArithmetic) implements ArithmeticListEvaluatorInterface {
            public function __construct(private ?Closure $evaluateArithmetic) {}

            public function evaluate(ListNode $list, bool $strict, Environment $env): ?AstNode
            {
                if ($this->evaluateArithmetic === null) {
                    return null;
                }

                return ($this->evaluateArithmetic)($list, $strict, $env);
            }
        },
        new class ($format) implements AstToSassValueConverterInterface {
            public function __construct(private Closure $format) {}

            public function convert(AstNode $node, Environment $env): SassValue
            {
                return new SassString(($this->format)($node, $env));
            }
        },
    );
}

describe('CalculationEvaluator', function () {
    beforeEach(function () {
        $this->env = new Environment();
    });

    it('returns null for unsupported calc argument shapes', function () {
        $evaluator = createCalculationEvaluator();

        expect($evaluator->simplifyFunction('calc', [], $this->env))->toBeNull()
            ->and($evaluator->simplifyFunction('calc', [new FunctionNode('var', [new StringNode('--gap')])], $this->env))->toBeNull()
            ->and($evaluator->simplifyFunction('calc', [
                new ListNode([new NumberNode(6), new StringNode('/'), new NumberNode(2)], 'space', true),
            ], $this->env))->toBeNull();
    });

    it('collapses calc lists through arithmetic evaluation when division shortcut does not apply', function () {
        $evaluator = createCalculationEvaluator(
            static fn(ListNode $node, bool $inCalc, Environment $env): ?AstNode => new NumberNode(42, 'px'),
        );

        $result = $evaluator->simplifyFunction('calc', [
            new ListNode([new NumberNode(40, 'px'), new StringNode('+'), new NumberNode(2, 'px')], 'space'),
        ], $this->env);

        expect($result)->toBeInstanceOf(NumberNode::class);

        /** @var NumberNode $result */
        expect($result->value)->toBe(42)
            ->and($result->unit)->toBe('px');
    });

    it('unwraps named calc arguments during normalization', function () {
        $evaluator = createCalculationEvaluator();

        $result = $evaluator->normalizeArguments('calc', [
            new NamedArgumentNode('size', new FunctionNode('calc', [new NumberNode(10, 'px')])),
        ]);

        expect($result)->toHaveCount(1)
            ->and($result[0])->toBeInstanceOf(NamedArgumentNode::class);

        /** @var NamedArgumentNode $namedArgument */
        $namedArgument = $result[0];

        expect($namedArgument->name)->toBe('size')
            ->and($namedArgument->value)->toBeInstanceOf(NumberNode::class);

        /** @var NumberNode $value */
        $value = $namedArgument->value;

        expect($value->value)->toBe(10)
            ->and($value->unit)->toBe('px');
    });

    it('detects nested calculations inside function, list, argument list and named arguments', function () {
        $evaluator = createCalculationEvaluator();

        $nestedNamed = new FunctionNode('rgb', [
            new NamedArgumentNode('channel', new FunctionNode('min', [new NumberNode(1), new NumberNode(2)])),
        ]);

        $nestedList = new ListNode([
            new StringNode('solid'),
            new FunctionNode('calc', [new NumberNode(1, 'px')]),
        ]);

        $nestedArgumentList = new ArgumentListNode([
            new StringNode('alpha'),
            new FunctionNode('calc', [new NumberNode(50, '%')]),
        ]);

        $plainFunction = new FunctionNode('rgb', [new NumberNode(1), new NumberNode(2), new NumberNode(3)]);

        expect($evaluator->detectUnsupportedOperation([$nestedNamed, new StringNode('+'), new NumberNode(1)], $this->env))
            ->not->toBeNull()
            ->and($evaluator->detectUnsupportedOperation([$nestedList, new StringNode('+'), new NumberNode(1)], $this->env))
            ->not->toBeNull()
            ->and($evaluator->detectUnsupportedOperation([$nestedArgumentList, new StringNode('+'), new NumberNode(1)], $this->env))
            ->not->toBeNull()
            ->and($evaluator->detectUnsupportedOperation([$plainFunction, new StringNode('+'), new NumberNode(1)], $this->env))
            ->toBeNull();
    });

    it('handles round edge cases for argument count step fallback and incompatible units', function () {
        $evaluator = createCalculationEvaluator();

        $deferredStep = $evaluator->simplifyFunction('round', [
            new StringNode('up'),
            new NumberNode(12, 'px'),
            new StringNode('var(--step)'),
        ], $this->env);

        expect($evaluator->simplifyFunction('round', [], $this->env))->toBeNull()
            ->and($evaluator->simplifyFunction('round', [
                new NumberNode(1),
                new NumberNode(2),
                new NumberNode(3),
                new NumberNode(4),
            ], $this->env))->toBeNull()
            ->and($deferredStep)->toBeInstanceOf(FunctionNode::class)
            ->and($evaluator->simplifyFunction('round', [new NumberNode(12, 'px'), new NumberNode(0, 'px')], $this->env))->toBeNull()
            ->and($evaluator->simplifyFunction('round', [new NumberNode(12, 'px'), new NumberNode(1, 's')], $this->env))->toBeNull();

        /** @var FunctionNode $deferredStep */
        expect($deferredStep->arguments)->toHaveCount(3)
            ->and($deferredStep->arguments[0])->toBeInstanceOf(StringNode::class)
            ->and($deferredStep->arguments[1])->toBeInstanceOf(NumberNode::class)
            ->and($deferredStep->arguments[2])->toBeInstanceOf(StringNode::class);

        /** @var StringNode $strategy */
        $strategy = $deferredStep->arguments[0];

        expect($strategy->value)->toBe('up');
    });

    it('resolves negative constants and leaves unsupported constant syntax untouched', function () {
        $evaluator = createCalculationEvaluator();

        $negativeInfinity = $evaluator->simplifyFunction('calc', [
            new ListNode([new StringNode('-'), new StringNode('infinity')]),
        ], $this->env);

        expect($negativeInfinity)->toBeInstanceOf(NumberNode::class)
            ->and($evaluator->simplifyFunction('calc', [
                new ListNode([new StringNode('+'), new StringNode('infinity')]),
            ], $this->env))->toBeNull();

        /** @var NumberNode $negativeInfinity */
        expect($negativeInfinity->value)->toBe(-INF)
            ->and($negativeInfinity->unit)->toBeNull();
    });

    it('returns null for calc constant lists that include non-string items', function () {
        $evaluator = createCalculationEvaluator();

        expect($evaluator->simplifyFunction('calc', [
            new ListNode([new StringNode('-'), new NumberNode(1, 'px')]),
        ], $this->env))->toBeNull();
    });
});
