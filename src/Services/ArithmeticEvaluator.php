<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\DivisionByZeroException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Exceptions\UndefinedOperationException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Utils\UnitConverter;
use Bugo\SCSS\Values\SassNumber;
use Closure;

use function count;
use function fmod;
use function trim;

final readonly class ArithmeticEvaluator
{
    /** @var array<string, true> */
    private const ARITHMETIC_OPERATORS = [
        '+' => true,
        '-' => true,
        '*' => true,
        '/' => true,
        '%' => true,
    ];

    /**
     * @param Closure(array<int, AstNode>): ?string|null $onUnsupportedOperation
     */
    public function evaluate(ListNode $node, bool $strict, ?Closure $onUnsupportedOperation = null): ?AstNode
    {
        if ($node->separator !== 'space') {
            return null;
        }

        try {
            $unary = $this->collapseUnary($node->items);

            if ($unary !== null) {
                return $unary;
            }

            if (count($node->items) < 3) {
                return null;
            }

            if (count($node->items) % 2 !== 0) {
                $strictResult = $this->evaluateStrictList($node->items, $node->bracketed);

                if ($strictResult !== null) {
                    return $strictResult;
                }
            }

            $items = $this->evaluateSegments($node->items, $node->bracketed);

            if ($items === null) {
                if (! $strict && $onUnsupportedOperation !== null) {
                    $unsupportedOperation = $onUnsupportedOperation($node->items);

                    if ($unsupportedOperation !== null) {
                        throw UndefinedOperationException::forExpression($unsupportedOperation);
                    }
                }

                return null;
            }

            $collapsedUnary = $this->collapseUnary($items);

            if ($collapsedUnary !== null) {
                return $collapsedUnary;
            }

            return new ListNode($items, $node->separator, $node->bracketed);
        } catch (IncompatibleUnitsException|DivisionByZeroException $exception) {
            if ($strict) {
                return null;
            }

            throw $exception;
        }
    }

    public function applyOperator(NumberNode $left, string $operator, NumberNode $right): NumberNode
    {
        if ($operator === '+' || $operator === '-') {
            if (! UnitConverter::compatible($left->unit, $right->unit)) {
                throw new IncompatibleUnitsException(
                    (string) new SassNumber($left->value, $left->unit),
                    (string) new SassNumber($right->value, $right->unit),
                );
            }

            $rightValue = UnitConverter::convert((float) $right->value, $right->unit, $left->unit);
            $value      = $operator === '+'
                ? (float) $left->value + $rightValue
                : (float) $left->value - $rightValue;

            return new NumberNode($value, $left->unit ?? $right->unit, false);
        }

        if ($operator === '*') {
            $unit = UnitConverter::multiply($left->unit, $right->unit);

            return new NumberNode((float) $left->value * (float) $right->value, $unit, false);
        }

        if ((float) $right->value === 0.0) {
            throw new DivisionByZeroException();
        }

        if ($operator === '%') {
            if (! UnitConverter::compatible($left->unit, $right->unit)) {
                throw new IncompatibleUnitsException(
                    (string) new SassNumber($left->value, $left->unit),
                    (string) new SassNumber($right->value, $right->unit),
                );
            }

            $rightValue = UnitConverter::convert((float) $right->value, $right->unit, $left->unit);

            return new NumberNode(
                fmod((float) $left->value, $rightValue),
                $left->unit ?? $right->unit,
                false,
            );
        }

        $unit = UnitConverter::divide($left->unit, $right->unit);

        return new NumberNode((float) $left->value / (float) $right->value, $unit, false);
    }

    /**
     * @param array<int, AstNode> $items
     */
    private function collapseUnary(array $items): ?NumberNode
    {
        if (
            count($items) !== 2
            || ! ($items[0] instanceof StringNode)
            || ! ($items[1] instanceof NumberNode)
        ) {
            return null;
        }

        $operator = trim($items[0]->value);

        if ($operator === '+') {
            return $items[1];
        }

        if ($operator !== '-') {
            return null;
        }

        return new NumberNode(-((float) $items[1]->value), $items[1]->unit);
    }

    /**
     * @param array<int, AstNode> $items
     */
    private function evaluateStrictList(array $items, bool $bracketed): ?NumberNode
    {
        $first = $items[0] ?? null;
        $mid   = $items[1] ?? null;
        $last  = $items[2] ?? null;

        if (! $bracketed
            && count($items) === 3
            && $first instanceof NumberNode
            && $first->isLiteral
            && $mid instanceof StringNode
            && $mid->value === '/'
            && $last instanceof NumberNode
            && $last->isLiteral
        ) {
            return null;
        }

        foreach ($items as $index => $token) {
            if ($index % 2 === 0 && ! ($token instanceof NumberNode)) {
                return null;
            }

            if (
                $index % 2 === 1
                && (! ($token instanceof StringNode) || ! isset(self::ARITHMETIC_OPERATORS[$token->value]))
            ) {
                return null;
            }
        }

        $collapsed = [];

        /** @var NumberNode $current */
        $current   = $items[0];
        $itemCount = count($items);

        for ($i = 1; $i < $itemCount; $i += 2) {
            /** @var StringNode $operator */
            $operator = $items[$i];

            /** @var NumberNode $next */
            $next = $items[$i + 1];

            if (
                $operator->value === '*'
                || $operator->value === '/'
                || $operator->value === '%'
            ) {
                $current = $this->applyOperator($current, $operator->value, $next);

                continue;
            }

            $collapsed[] = $current;
            $collapsed[] = $operator;

            $current = $next;
        }

        $collapsed[] = $current;

        /** @var NumberNode $result */
        $result         = $collapsed[0];
        $collapsedCount = count($collapsed);

        for ($i = 1; $i < $collapsedCount; $i += 2) {
            /** @var StringNode $operator */
            $operator = $collapsed[$i];

            /** @var NumberNode $next */
            $next   = $collapsed[$i + 1];
            $result = $this->applyOperator($result, $operator->value, $next);
        }

        return $result;
    }

    /**
     * @param array<int, AstNode> $items
     * @return array<int, AstNode>|null
     */
    private function evaluateSegments(array $items, bool $bracketed): ?array
    {
        $result  = [];
        $count   = count($items);
        $changed = false;

        if (! $bracketed
            && $count >= 3
            && $items[0] instanceof NumberNode
            && $items[0]->isLiteral
            && $items[1] instanceof StringNode
            && $items[1]->value === '/'
            && $items[2] instanceof NumberNode
            && $items[2]->isLiteral
        ) {
            return null;
        }

        for ($i = 0; $i < $count; $i++) {
            $current   = $items[$i];
            $nextToken = $items[$i + 1] ?? null;

            if (
                ! ($current instanceof NumberNode)
                || $i + 2 >= $count
                || ! ($nextToken instanceof StringNode)
                || ! isset(self::ARITHMETIC_OPERATORS[$nextToken->value])
                || ! ($items[$i + 2] instanceof NumberNode)
            ) {
                $result[] = $current;

                continue;
            }

            $value = $current;

            while (
                $i + 2 < $count
                && ($nextToken = $items[$i + 1] ?? null) instanceof StringNode
                && isset(self::ARITHMETIC_OPERATORS[$nextToken->value])
                && ($nextItem = $items[$i + 2]) instanceof NumberNode
                && ! ($nextToken->value === '/' && $value->isLiteral && $nextItem->isLiteral)
            ) {
                $next    = $nextItem;
                $value   = $this->applyOperator($value, $nextToken->value, $next);
                $changed = true;

                $i += 2;
            }

            $result[] = $value;
        }

        if (! $changed) {
            return null;
        }

        return $result;
    }
}
