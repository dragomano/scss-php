<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Exceptions\DivisionByZeroException;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Exceptions\UndefinedOperationException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Utils\UnitConverter;
use Bugo\SCSS\Values\SassNumber;
use Closure;

use function count;
use function fdiv;
use function floor;
use function in_array;
use function is_infinite;
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
    public function evaluate(ListNode $node, bool $strict, ?Closure $onUnsupportedOperation = null, bool $insideCalc = false): ?AstNode
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
                try {
                    $strictResult = $this->evaluateStrictList($node->items, $node->bracketed, $insideCalc);
                } catch (IncompatibleUnitsException $exception) {
                    if (! $insideCalc) {
                        throw $exception;
                    }

                    $strictResult = null;
                }

                if ($strictResult !== null) {
                    return $strictResult;
                }
            }

            $items = $this->evaluateSegments($node->items, $node->bracketed, $insideCalc);

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

    public function applyOperator(NumberNode $left, string $operator, NumberNode $right, bool $insideCalc = false): AstNode
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
            [$unit, $conversionFactor] = UnitConverter::multiplyWithConversion($left->unit, $right->unit);

            return new NumberNode((float) $left->value * (float) $right->value * $conversionFactor, $unit, false);
        }

        if ((float) $right->value === 0.0) {
            if (! $insideCalc) {
                throw new DivisionByZeroException();
            }

            return $this->applyDegenerateDivision($left, $operator, $right);
        }

        if ($operator === '%') {
            if (! UnitConverter::compatible($left->unit, $right->unit)) {
                throw new IncompatibleUnitsException(
                    (string) new SassNumber($left->value, $left->unit),
                    (string) new SassNumber($right->value, $right->unit),
                );
            }

            $rightValue = UnitConverter::convert((float) $right->value, $right->unit, $left->unit);

            if (is_infinite($rightValue)) {
                $sameSign = ($left->value >= 0) === ($rightValue >= 0);

                if ($sameSign) {
                    return new NumberNode((float) $left->value, $left->unit ?? $right->unit, false);
                }

                return new FunctionNode('calc', [
                    new ListNode([
                        new StringNode('NaN'),
                        new StringNode('*'),
                        new NumberNode(1.0, $left->unit ?? $right->unit, false),
                    ], 'space'),
                ]);
            }

            $leftValue = (float) $left->value;

            return new NumberNode(
                $leftValue - $rightValue * floor($leftValue / $rightValue),
                $left->unit ?? $right->unit,
                false,
            );
        }

        [$unit, $conversionFactor] = UnitConverter::divideWithConversion($left->unit, $right->unit);

        return new NumberNode((float) $left->value / (float) $right->value * $conversionFactor, $unit, false);
    }

    private function applyDegenerateDivision(NumberNode $left, string $operator, NumberNode $right): NumberNode
    {
        if ($operator === '%') {
            return new NumberNode(fdiv(0.0, 0.0), $left->unit ?? $right->unit, false);
        }

        [$unit, $conversionFactor] = UnitConverter::divideWithConversion($left->unit, $right->unit);

        return new NumberNode(
            fdiv((float) $left->value * $conversionFactor, (float) $right->value),
            $unit,
            false,
        );
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

    private function isSimpleSlashOperand(NumberNode $number): bool
    {
        return $number->isLiteral && ! $number->parenthesized;
    }

    /**
     * @param array<int, AstNode> $items
     */
    private function isSimpleSlashChain(array $items): bool
    {
        $count = count($items);

        if ($count < 3 || $count % 2 === 0) {
            return false;
        }

        for ($i = 0; $i < $count; $i++) {
            $item = $items[$i];

            if ($i % 2 === 0) {
                if (! $item instanceof NumberNode
                    || ! $this->isSimpleSlashOperand($item)
                    || $item->unit !== null
                ) {
                    return false;
                }
            } elseif (! $item instanceof StringNode || $item->value !== '/') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, AstNode> $items
     */
    private function evaluateStrictList(array $items, bool $bracketed, bool $insideCalc = false): ?AstNode
    {
        $first = $items[0] ?? null;
        $mid   = $items[1] ?? null;
        $last  = $items[2] ?? null;

        if (! $bracketed
            && ! $insideCalc
            && count($items) === 3
            && $first instanceof NumberNode
            && $this->isSimpleSlashOperand($first)
            && $mid instanceof StringNode
            && $mid->value === '/'
            && $last instanceof NumberNode
            && $this->isSimpleSlashOperand($last)
        ) {
            return null;
        }

        if (! $bracketed && ! $insideCalc && $this->isSimpleSlashChain($items)) {
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

        /** @var AstNode $current */
        $current   = $items[0];
        $itemCount = count($items);

        for ($i = 1; $i < $itemCount; $i += 2) {
            /** @var StringNode $operator */
            $operator = $items[$i];

            /** @var NumberNode $next */
            $next = $items[$i + 1];

            if (
                ($operator->value === '*'
                    || $operator->value === '/'
                    || $operator->value === '%')
                && $current instanceof NumberNode
            ) {
                $current = $this->applyOperator($current, $operator->value, $next, $insideCalc);

                continue;
            }

            $collapsed[] = $current;
            $collapsed[] = $operator;

            $current = $next;
        }

        $collapsed[] = $current;

        /** @var AstNode $result */
        $result         = $collapsed[0];
        $collapsedCount = count($collapsed);

        for ($i = 1; $i < $collapsedCount; $i += 2) {
            /** @var StringNode $operator */
            $operator = $collapsed[$i];

            /** @var NumberNode $next */
            $next = $collapsed[$i + 1];

            if (! $result instanceof NumberNode) {
                return $result;
            }

            $result = $this->applyOperator($result, $operator->value, $next, $insideCalc);
        }

        return $result;
    }

    /**
     * @param array<int, AstNode> $items
     * @return array<int, AstNode>|null
     */
    private function evaluateSegments(array $items, bool $bracketed, bool $insideCalc = false): ?array
    {
        $count = count($items);

        if (! $bracketed
            && ! $insideCalc
            && $count >= 3
            && $items[0] instanceof NumberNode
            && $this->isSimpleSlashOperand($items[0])
            && $items[1] instanceof StringNode
            && $items[1]->value === '/'
            && $items[2] instanceof NumberNode
            && $this->isSimpleSlashOperand($items[2])
        ) {
            return null;
        }

        $changed = false;
        $pass1   = $this->foldMultiplicativeSegments($items, $changed, $insideCalc);
        $result  = $this->foldAdditiveSegments($pass1, $changed);

        if (! $changed) {
            return null;
        }

        return $result;
    }

    /**
     * @param array<int, AstNode> $items
     * @return array<int, AstNode>
     */
    private function foldMultiplicativeSegments(array $items, bool &$changed, bool $insideCalc): array
    {
        $result = [];
        $count  = count($items);

        for ($i = 0; $i < $count; $i++) {
            $item = $items[$i];
            $op   = $items[$i + 1] ?? null;
            $next = $items[$i + 2] ?? null;

            if (
                ! ($item instanceof NumberNode)
                || ! ($op instanceof StringNode)
                || ! in_array($op->value, ['*', '/', '%'], true)
                || ! ($next instanceof NumberNode)
            ) {
                $result[] = $item;

                continue;
            }

            $value = $item;

            while (
                $i + 2 < $count
                && ($op = $items[$i + 1] ?? null) instanceof StringNode
                && in_array($op->value, ['*', '/', '%'], true)
                && ($nextItem = $items[$i + 2]) instanceof NumberNode
                && $value instanceof NumberNode
                && ($insideCalc || ! ($op->value === '/' && $this->isSimpleSlashOperand($value) && $this->isSimpleSlashOperand($nextItem)))
            ) {
                $value   = $this->applyOperator($value, $op->value, $nextItem, $insideCalc);
                $changed = true;

                $i += 2;
            }

            $result[] = $value;

            if ($i + 1 < $count) {
                $result[] = $items[$i + 1];

                $i++;
            }
        }

        return $result;
    }

    /**
     * @param array<int, AstNode> $items
     * @return array<int, AstNode>
     */
    private function foldAdditiveSegments(array $items, bool &$changed): array
    {
        $result = [];
        $count  = count($items);
        $i      = 0;

        while ($i < $count) {
            $next = $items[$i + 1] ?? null;

            $isChainStart = $items[$i] instanceof NumberNode
                && $next instanceof StringNode
                && in_array($next->value, ['+', '-'], true)
                && isset($items[$i + 2])
                && $items[$i + 2] instanceof NumberNode;

            if (! $isChainStart) {
                $result[] = $items[$i];

                $i++;

                continue;
            }

            $value = $items[$i];
            $j     = $i;

            while (
                $j + 2 < $count
                && ($op = $items[$j + 1] ?? null) instanceof StringNode
                && in_array($op->value, ['+', '-'], true)
                && ($nextItem = $items[$j + 2]) instanceof NumberNode
                && $value instanceof NumberNode
            ) {
                try {
                    $value   = $this->applyOperator($value, $op->value, $nextItem);
                    $changed = true;

                    $j += 2;
                } catch (IncompatibleUnitsException) {
                    break;
                }
            }

            $result[] = $value;

            $chainOp        = $items[$j + 1] ?? null;
            $chainContinues = $j + 1 < $count
                && $chainOp instanceof StringNode
                && in_array($chainOp->value, ['+', '-'], true);

            if ($chainContinues) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $result[] = $items[$k];
                }

                break;
            }

            $i = $j + 1;
        }

        return $result;
    }
}
