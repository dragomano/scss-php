<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\Environment;

use function count;
use function ctype_alpha;
use function in_array;
use function strlen;
use function trim;

final readonly class StringConcatenationEvaluator
{
    public function __construct(private AstValueFormatterInterface $valueFormatter) {}

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

        $hasQuoted  = false;
        $allStrings = true;

        foreach ($list->items as $index => $item) {
            if ($index % 2 === 1) {
                if (! ($item instanceof StringNode) || ! in_array($item->value, ['+', '-'], true)) {
                    return null;
                }
            } else {
                if ($item instanceof StringNode) {
                    $hasQuoted = $hasQuoted || $item->quoted;
                } else {
                    $allStrings = false;
                }
            }
        }

        if (! $hasQuoted && ! $allStrings) {
            return null;
        }

        if (! $hasQuoted && $this->containsNumericLikeStringOperand($list)) {
            return null;
        }

        $result = '';
        $quoted = false;

        foreach ($list->items as $index => $item) {
            if ($index % 2 === 1) {
                /** @var StringNode $item */
                if ($item->value === '-') {
                    $result .= '-';
                }

                continue;
            }

            if ($item instanceof StringNode) {
                $quoted  = $quoted || $item->quoted;
                $result .= $item->value;
            } else {
                $result .= $this->valueFormatter->format($item, $env);
            }
        }

        return new StringNode($result, $quoted);
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
