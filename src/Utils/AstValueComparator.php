<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\ValueFactory;

use function count;

final class AstValueComparator
{
    public static function equals(AstNode $left, AstNode $right): bool
    {
        $left  = self::unwrapSingleParenthesizedList($left);
        $right = self::unwrapSingleParenthesizedList($right);

        if ($left instanceof BooleanNode && $right instanceof BooleanNode) {
            return $left->value === $right->value;
        }

        if ($left instanceof NullNode && $right instanceof NullNode) {
            return true;
        }

        if ($left instanceof NumberNode && $right instanceof NumberNode) {
            return (float) $left->value === (float) $right->value && $left->unit === $right->unit;
        }

        if ($left instanceof StringNode && $right instanceof StringNode) {
            return $left->value === $right->value;
        }

        if ($left instanceof StringNode && $right instanceof FunctionNode) {
            return $left->value === self::functionToCss($right);
        }

        if ($left instanceof FunctionNode && $right instanceof StringNode) {
            return self::functionToCss($left) === $right->value;
        }

        if ($left instanceof ColorNode && $right instanceof ColorNode) {
            return $left->value === $right->value;
        }

        if ($left instanceof FunctionNode && $right instanceof FunctionNode) {
            if ($left->name !== $right->name || count($left->arguments) !== count($right->arguments)) {
                return false;
            }

            foreach ($left->arguments as $index => $argument) {
                if (! self::equals($argument, $right->arguments[$index])) {
                    return false;
                }
            }

            return true;
        }

        if ($left instanceof ListNode && $right instanceof ListNode) {
            if ($left->separator !== $right->separator && count($left->items) > 1 && count($right->items) > 1) {
                return false;
            }

            if ($left->bracketed !== $right->bracketed || count($left->items) !== count($right->items)) {
                return false;
            }

            foreach ($left->items as $index => $item) {
                if (! self::equals($item, $right->items[$index])) {
                    return false;
                }
            }

            return true;
        }

        if ($left instanceof MapNode && $right instanceof MapNode) {
            if (count($left->pairs) !== count($right->pairs)) {
                return false;
            }

            foreach ($left->pairs as $index => $pair) {
                if (! self::equals($pair->key, $right->pairs[$index]->key)) {
                    return false;
                }

                if (! self::equals($pair->value, $right->pairs[$index]->value)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }

    private static function unwrapSingleParenthesizedList(AstNode $node): AstNode
    {
        if (
            $node instanceof ListNode
            && $node->parenthesized > 0
            && ! $node->bracketed
            && count($node->items) === 1
        ) {
            return $node->items[0];
        }

        return $node;
    }

    private static function functionToCss(FunctionNode $node): ?string
    {
        if ($node->capturedScope !== null) {
            return null;
        }

        return (new ValueFactory())->fromAst($node)->toCss();
    }
}
