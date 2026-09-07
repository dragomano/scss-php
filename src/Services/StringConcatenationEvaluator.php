<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\DivisionByZeroException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Values\SassCalculation;

use function count;
use function ctype_alpha;
use function in_array;
use function strlen;
use function strtolower;
use function trim;

final readonly class StringConcatenationEvaluator
{
    public function __construct(
        private AstValueFormatterInterface $valueFormatter,
        private ArithmeticEvaluator $arithmetic,
    ) {}

    public function evaluate(ListNode $list, ?Environment $env = null): ?AstNode
    {
        if ($list->separator !== 'space') {
            return null;
        }

        $count = count($list->items);
        $env ??= new Environment();

        // Handle unary prefix: ['-' | '/', value] → '-value' or '/value'
        if ($count === 2
            && $list->items[0] instanceof StringNode
            && in_array($list->items[0]->value, ['-', '/'], true)
        ) {
            return new StringNode($list->items[0]->value . $this->valueFormatter->format($list->items[1], $env));
        }

        if ($count === 3) {
            $collapsedDimension = $this->collapseNumberWithUnitSuffix($list);

            if ($collapsedDimension !== null) {
                return $collapsedDimension;
            }
        }

        if ($count < 3 || $count % 2 === 0) {
            return null;
        }

        $list = $this->unwrapParenthesizedOperands($list);
        $list = $this->foldIsolatedDivisions($list);

        $hasQuoted        = false;
        $allStrings       = true;
        $hasCssFunction   = false;
        $hasCalcStructure = false;

        foreach ($list->items as $index => $item) {
            if ($index % 2 === 1) {
                if (
                    ! ($item instanceof StringNode)
                    || $item->quoted
                    || ! in_array($item->value, ['+', '-'], true)
                ) {
                    return null;
                }
            } else {
                if ($item instanceof ColorNode) {
                    continue;
                }

                if ($item instanceof FunctionNode) {
                    if (SassCalculation::isCalculationFunctionName($item->name)
                        || in_array(strtolower($item->name), ['var', 'env'], true)
                    ) {
                        $hasCalcStructure = true;
                    } else {
                        $hasCssFunction = true;
                    }

                    continue;
                }

                if ($item instanceof StringNode) {
                    $hasQuoted = $hasQuoted || $item->quoted;
                } else {
                    $allStrings = false;
                }
            }
        }

        if (! $hasQuoted && $hasCalcStructure) {
            return null;
        }

        if (! $hasQuoted && ! $allStrings && ! $hasCssFunction) {
            return null;
        }

        if (! $hasQuoted && $this->containsNumericLikeStringOperand($list)) {
            return null;
        }

        $result = '';
        $quoted = null;

        foreach ($list->items as $index => $item) {
            if ($index % 2 === 1) {
                /** @var StringNode $item */
                if ($item->value === '-') {
                    $result .= '-';
                }

                continue;
            }

            if ($item instanceof StringNode) {
                $quoted ??= $item->quoted;
                $result .= $item->value;
            } else {
                $result .= $this->valueFormatter->format($item, $env);
            }
        }

        return new StringNode($result, $quoted ?? false);
    }

    private function foldIsolatedDivisions(ListNode $list): ListNode
    {
        $items   = $list->items;
        $count   = count($items);
        $folded  = [];
        $changed = false;

        for ($i = 0; $i < $count; $i++) {
            $item     = $items[$i];
            $operator = $items[$i + 1] ?? null;
            $right    = $items[$i + 2] ?? null;
            $after    = $items[$i + 3] ?? null;

            if ($item instanceof NumberNode
                && $operator instanceof StringNode
                && $operator->value === '/'
                && $right instanceof NumberNode
                && $this->isConcatBoundary($i === 0 ? null : $items[$i - 1])
                && $this->isConcatBoundary($after)
            ) {
                try {
                    $folded[] = $this->arithmetic->applyOperator($item, '/', $right);
                    $changed  = true;

                    $i += 2;

                    continue;
                } catch (DivisionByZeroException|IncompatibleUnitsException) {
                }
            }

            $folded[] = $item;
        }

        if (! $changed) {
            return $list;
        }

        return new ListNode($folded, $list->separator, $list->bracketed, $list->parenthesized);
    }

    private function isConcatBoundary(?AstNode $node): bool
    {
        if ($node === null) {
            return true;
        }

        return $node instanceof StringNode && ($node->value === '+' || $node->value === '-');
    }

    private function unwrapParenthesizedOperands(ListNode $list): ListNode
    {
        $items   = $list->items;
        $changed = false;

        foreach ($items as $index => $item) {
            if ($index % 2 === 1) {
                continue;
            }

            if (
                $item instanceof ListNode
                && $item->parenthesized > 0
                && ! $item->bracketed
                && count($item->items) === 1
                && $item->items[0] instanceof StringNode
            ) {
                $items[$index] = $item->items[0];
                $changed       = true;
            }
        }

        if (! $changed) {
            return $list;
        }

        return new ListNode($items, $list->separator, $list->bracketed, $list->parenthesized);
    }

    private function collapseNumberWithUnitSuffix(ListNode $list): ?AstNode
    {
        [$left, $operator, $right] = $list->items;

        if (
            ! $left instanceof NumberNode
            || $left->unit !== null
            || ! $operator instanceof StringNode
            || $operator->value !== '+'
            || ! $right instanceof StringNode
            || $right->quoted
            || ! $this->isUnitSuffix($right->value)
        ) {
            return null;
        }

        return new NumberNode($left->value, trim($right->value), $left->isLiteral);
    }

    private function containsNumericLikeStringOperand(ListNode $list): bool
    {
        foreach ($list->items as $index => $item) {
            if ($index % 2 === 1 || ! $item instanceof StringNode || $item->quoted) {
                continue;
            }

            if ($this->isNumericLikeString($item->value)) {
                return true;
            }
        }

        return false;
    }

    private function isUnitSuffix(string $value): bool
    {
        $value = trim($value);

        if ($value === '%') {
            return true;
        }

        $length = strlen($value);

        if ($length === 0) {
            return false;
        }

        for ($index = 0; $index < $length; $index++) {
            if (! ctype_alpha($value[$index])) {
                return false;
            }
        }

        return true;
    }

    private function isNumericLikeString(string $value): bool
    {
        $value = trim($value);

        if ($value === '') {
            return false;
        }

        $first = $value[0];

        if ($first >= '0' && $first <= '9') {
            return true;
        }

        if (
            $first === '.'
            && isset($value[1])
            && $value[1] >= '0'
            && $value[1] <= '9'
        ) {
            return true;
        }

        if (
            ($first === '+' || $first === '-')
            && isset($value[1])
            && (
                ($value[1] >= '0' && $value[1] <= '9')
                || ($value[1] === '.' && isset($value[2]) && $value[2] >= '0' && $value[2] <= '9')
            )
        ) {
            return true;
        }

        return false;
    }
}
