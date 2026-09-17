<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\DivisionByZeroException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Values\SassCalculation;
use Closure;

use function array_slice;
use function count;
use function ctype_alpha;
use function in_array;
use function strlen;
use function strtolower;
use function trim;

final readonly class StringConcatenationEvaluator
{
    private const OPERATOR_WORDS = ['+', '-', '*', '/', '%', 'and', 'or', 'not'];

    /**
     * @param (Closure(AstNode): bool)|null $isTruthy
     */
    public function __construct(
        private AstValueFormatterInterface $valueFormatter,
        private ArithmeticEvaluator $arithmetic,
        private ?Closure $isTruthy = null,
    ) {}

    public function evaluate(ListNode $list, ?Environment $env = null): ?AstNode
    {
        if ($list->separator !== 'space') {
            return null;
        }

        $count = count($list->items);
        $env ??= new Environment();

        if ($count === 2
            && $list->items[0] instanceof StringNode
            && in_array($list->items[0]->value, ['-', '+', '/'], true)
        ) {
            return new StringNode($list->items[0]->value . $this->valueFormatter->format($list->items[1], $env));
        }

        if ($count >= 3) {
            $unaryChain = $this->foldLeadingUnaryChain($list->items, $env);

            if ($unaryChain !== null) {
                return $unaryChain;
            }
        }

        if ($count === 3) {
            $collapsedDimension = $this->collapseNumberWithUnitSuffix($list);

            if ($collapsedDimension !== null) {
                return $collapsedDimension;
            }
        }

        if ($count < 3) {
            return null;
        }

        $list = $this->unwrapParenthesizedOperands($list);
        $list = $this->foldIsolatedDivisions($list);

        $items   = $list->items;
        $anyFold = false;

        for ($pass = 0; $pass < 16; $pass++) {
            [$items, $foldedNot] = $this->foldNotOperators($items);
            [$items, $foldedPairs] = $this->foldBinaryPairs($items, $env);

            if (! $foldedNot && ! $foldedPairs) {
                break;
            }

            $anyFold = true;
        }

        if (! $anyFold) {
            return null;
        }

        if ($this->isBareOperator($items[0]) || $this->isBareOperator($items[count($items) - 1])) {
            return null;
        }

        if (count($items) === 1) {
            return $items[0];
        }

        return new ListNode($items, $list->separator, $list->bracketed, $list->parenthesized);
    }

    /**
     * @param array<int, AstNode> $items
     */
    private function foldLeadingUnaryChain(array $items, Environment $env): ?AstNode
    {
        $count = count($items);

        $operators = '';

        foreach (array_slice($items, 0, $count - 1) as $item) {
            if (
                ! $item instanceof StringNode
                || $item->quoted
                || $item->isSpecialString
                || ! in_array($item->value, ['+', '-'], true)
            ) {
                return null;
            }

            $operators .= $item->value;
        }

        $operand = $items[$count - 1];

        if (! $this->isFoldableOperand($operand)) {
            return null;
        }

        return new StringNode($operators . $this->valueFormatter->format($operand, $env));
    }

    private function isBareOperator(AstNode $node): bool
    {
        if (! $node instanceof StringNode || $node->quoted) {
            return false;
        }

        if ($node->value === '/') {
            return $node->isSlashOperator;
        }

        return in_array(strtolower(trim($node->value)), self::OPERATOR_WORDS, true);
    }

    /**
     * @param array<int, AstNode> $items
     * @return array{0: array<int, AstNode>, 1: bool}
     */
    private function foldNotOperators(array $items): array
    {
        $result  = [];
        $changed = false;
        $count   = count($items);

        for ($index = 0; $index < $count; $index++) {
            $item = $items[$index];
            $next = $items[$index + 1] ?? null;

            if ($this->isNotOperator($item) && $next !== null && $this->isFoldableOperand($next)) {
                if ($this->isTruthy !== null) {
                    $result[] = new BooleanNode(! ($this->isTruthy)($next));
                    $changed  = true;

                    $index++;

                    continue;
                }
            }

            $result[] = $item;
        }

        return [$result, $changed];
    }

    /**
     * @param array<int, AstNode> $items
     * @return array{0: array<int, AstNode>, 1: bool}
     */
    private function foldBinaryPairs(array $items, Environment $env): array
    {
        $result  = [];
        $changed = false;
        $count   = count($items);

        for ($index = 0; $index < $count; $index++) {
            $left     = $items[$index];
            $operator = $items[$index + 1] ?? null;
            $right    = $items[$index + 2] ?? null;

            if ($this->canFoldPair($left, $operator, $right)) {
                if ($operator instanceof StringNode && $right instanceof AstNode) {
                    $result[] = $this->concatPair($left, $operator->value, $right, $env);
                    $changed  = true;

                    $index += 2;

                    continue;
                }
            }

            $result[] = $left;
        }

        return [$result, $changed];
    }

    private function canFoldPair(?AstNode $left, ?AstNode $operator, ?AstNode $right): bool
    {
        if (! $operator instanceof StringNode
            || $operator->quoted
            || $operator->isSpecialString
            || ! in_array($operator->value, ['+', '-'], true)
        ) {
            return false;
        }

        if ($left === null || $right === null || ! $this->isFoldableOperand($right) || ! $this->isFoldableOperand($left)) {
            return false;
        }

        if ($this->isCalculationFamilyOperand($left) || $this->isCalculationFamilyOperand($right)) {
            $other = $this->isCalculationFamilyOperand($left) ? $right : $left;

            return $other instanceof StringNode;
        }

        return ! ($left instanceof NumberNode && $right instanceof NumberNode);
    }

    private function isFoldableOperand(?AstNode $node): bool
    {
        if ($node instanceof StringNode) {
            if ($node->quoted) {
                return true;
            }

            if ($node->value === '/') {
                return ! $node->isSlashOperator;
            }

            return ! in_array(strtolower(trim($node->value)), self::OPERATOR_WORDS, true);
        }

        return $node instanceof NumberNode
            || $node instanceof BooleanNode
            || $node instanceof ColorNode
            || $node instanceof NullNode
            || $node instanceof ListNode
            || $node instanceof FunctionNode;
    }

    private function isCalculationFamilyOperand(AstNode $node): bool
    {
        return $node instanceof FunctionNode && SassCalculation::isCalculationFunctionName($node->name);
    }

    private function isNotOperator(?AstNode $node): bool
    {
        return $node instanceof StringNode
            && ! $node->quoted
            && strtolower(trim($node->value)) === 'not';
    }

    private function concatPair(AstNode $left, string $operator, AstNode $right, Environment $env): StringNode
    {
        $leftIsString  = $left instanceof StringNode;
        $rightIsString = $right instanceof StringNode;

        /** @var bool $quoted */
        $quoted = false;
        if ($operator === '+') {
            if ($leftIsString) {
                /** @var StringNode $left */
                $quoted = $left->quoted;
            } elseif ($rightIsString) {
                /** @var StringNode $right */
                $quoted = $right->quoted;
            }
        }

        $result = $this->formatOperand($left, $leftIsString, $operator, $env);

        if ($operator !== '+') {
            $result .= $operator;
        }

        $result .= $this->formatOperand($right, $rightIsString, $operator, $env);

        return new StringNode($result, $quoted);
    }

    private function formatOperand(AstNode $node, bool $isString, string $operator, Environment $env): string
    {
        if ($isString && $node instanceof StringNode) {
            if ($node->quoted && $operator !== '+') {
                return $this->valueFormatter->format($node, $env);
            }

            return $node->value;
        }

        return $this->valueFormatter->format($node, $env);
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
}
