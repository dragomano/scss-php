<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;

use function implode;

final class AstValueSuggestionDescriber
{
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

    public static function describe(?AstNode $value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof ListNode || $value instanceof ArgumentListNode) {
            $items = self::describeArguments($value->items);
            $glue  = match ($value->separator) {
                'comma' => ', ',
                'slash' => ' / ',
                default => ' ',
            };
            $text = implode($glue, $items);

            if ($value->bracketed) {
                return '[' . $text . ']';
            }

            if ($value->items === []) {
                return '()';
            }

            if ($value->separator !== 'space') {
                return '(' . $text . ')';
            }

            return $text;
        }

        if ($value instanceof MapNode) {
            $pairs = [];

            foreach ($value->pairs as $pair) {
                $pairs[] = self::describe($pair->key) . ': ' . self::describe($pair->value);
            }

            return '(' . implode(', ', $pairs) . ')';
        }

        return AstValueDescriber::describe($value);
    }
}
