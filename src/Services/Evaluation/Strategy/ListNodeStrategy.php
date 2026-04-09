<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Closure;

use function count;

final readonly class ListNodeStrategy implements EvaluationStrategyInterface
{
    use LazilyEvaluatesItems;

    /**
     * @param Closure(AstNode, Environment, EvaluationOptions): AstNode $evaluateValue
     * @param Closure(ListNode, Environment): ?AstNode $evaluateLogicalList
     * @param Closure(ListNode, bool, Environment): ?AstNode $evaluateArithmeticList
     * @param Closure(ListNode, ?Environment): ?AstNode $evaluateStringConcatenationList
     */
    public function __construct(
        private Closure $evaluateValue,
        private Closure $evaluateLogicalList,
        private Closure $evaluateArithmeticList,
        private Closure $evaluateStringConcatenationList,
    ) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof ListNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var ListNode $node */
        $items = self::lazilyEvaluateItems(
            $node->items,
            function (AstNode $item) use ($env, $options): AstNode {
                if ($item instanceof ListNode
                    && $item->separator === 'space'
                    && count($item->items) === 3
                ) {
                    [$itemFirst, $itemMid, $itemLast] = $item->items;

                    if ($itemFirst instanceof NumberNode
                        && $itemMid instanceof StringNode
                        && $itemMid->value === '/'
                        && $itemLast instanceof NumberNode
                    ) {
                        return $item;
                    }
                }

                return ($this->evaluateValue)($item, $env, $options);
            },
        );

        $evaluated = $items !== null
            ? new ListNode($items, $node->separator, $node->bracketed)
            : $node;

        $logical = ($this->evaluateLogicalList)($evaluated, $env);

        if ($logical !== null) {
            return $logical;
        }

        if (! $options->skipSlashArithmetic) {
            $arithmetic = ($this->evaluateArithmeticList)($evaluated, true, $env);

            if ($arithmetic !== null) {
                return $arithmetic;
            }
        }

        $concatenation = ($this->evaluateStringConcatenationList)($evaluated, $env);

        return $concatenation ?? $evaluated;
    }
}
