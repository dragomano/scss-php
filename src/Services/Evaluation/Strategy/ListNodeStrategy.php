<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Closure;

use function count;
use function in_array;

final readonly class ListNodeStrategy implements EvaluationStrategyInterface
{
    use LazilyEvaluatesItems;

    /**
     * @param Closure(AstNode, Environment, EvaluationOptions): AstNode $evaluateValue
     * @param Closure(ListNode, Environment): ?AstNode $evaluateLogicalList
     * @param Closure(ListNode, bool, Environment): ?AstNode $evaluateArithmeticList
     * @param Closure(ListNode, ?Environment, EvaluationOptions): ?AstNode $evaluateStringConcatenationList
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
        $sourceItems = $this->foldSlashTriples($node);
        $items       = self::lazilyEvaluateItems(
            $sourceItems,
            function (AstNode $item) use ($env, $options): AstNode {
                if ($item instanceof ListNode
                    && $item->separator === 'space'
                    && ! $item->bracketed
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

        $evaluatedItems = $items ?? $sourceItems;

        $evaluated = new ListNode(
            $evaluatedItems,
            $node->separator,
            $node->bracketed,
            $node->parenthesized,
            $node->isComputed,
        );

        if ($evaluated->isComputed) {
            return $evaluated;
        }

        if (count($evaluated->items) === 1) {
            if ($evaluated->parenthesized > 0 && $evaluated->items[0] instanceof NullNode) {
                return $evaluated->items[0];
            }

            return $evaluated;
        }

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

        $concatenation = ($this->evaluateStringConcatenationList)($evaluated, $env, $options);

        return $concatenation ?? $evaluated;
    }

    /**
     * @return array<int, AstNode>
     */
    private function foldSlashTriples(ListNode $node): array
    {
        if ($node->separator !== 'space' || $node->bracketed || $node->isComputed || count($node->items) <= 3) {
            return $node->items;
        }

        $items = $node->items;
        $count = count($items);

        $result = [];

        for ($i = 0; $i < $count; $i++) {
            $current = $items[$i];

            $operator = $items[$i + 1] ?? null;

            if (
                $i + 2 < $count
                && $current instanceof NumberNode
                && $operator instanceof StringNode
                && $operator->value === '/'
                && $items[$i + 2] instanceof NumberNode
                && ($i === 0 || ! $this->isSlashLikeOperator($items[$i - 1]))
                && ($i + 3 >= $count || ! $this->isSlashLikeOperator($items[$i + 3]))
            ) {
                $result[] = new ListNode([$current, $items[$i + 1], $items[$i + 2]], 'space');

                $i += 2;

                continue;
            }

            $result[] = $current;
        }

        return $result;
    }

    private function isSlashLikeOperator(AstNode $node): bool
    {
        return $node instanceof StringNode
            && in_array($node->value, ['+', '-', '*', '%', '/'], true);
    }
}
