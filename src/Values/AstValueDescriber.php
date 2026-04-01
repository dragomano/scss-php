<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;

use function implode;

final class AstValueDescriber
{
    public static function describe(AstNode $value): string
    {
        if ($value instanceof VariableReferenceNode) {
            return '$' . $value->name;
        }

        if ($value instanceof NumberNode) {
            return "$value->value" . ($value->unit ?? '');
        }

        if ($value instanceof StringNode) {
            return $value->quoted ? '"' . $value->value . '"' : $value->value;
        }

        if ($value instanceof ListNode) {
            $items = self::describeArguments($value->items);
            $glue  = $value->separator === 'comma' ? ', ' : ' ';
            $text  = implode($glue, $items);

            if ($value->bracketed) {
                return '[' . $text . ']';
            }

            return $text;
        }

        if ($value instanceof MapNode) {
            $pairs = [];

            foreach ($value->pairs as $pair) {
                $pairs[] = self::describe($pair['key']) . ': ' . self::describe($pair['value']);
            }

            return '(' . implode(', ', $pairs) . ')';
        }

        if ($value instanceof ColorNode) {
            return $value->value;
        }

        return '';
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, string>
     */
    public static function describeArguments(array $arguments): array
    {
        $described = [];

        foreach ($arguments as $argument) {
            $described[] = self::describe($argument);
        }

        return $described;
    }
}
