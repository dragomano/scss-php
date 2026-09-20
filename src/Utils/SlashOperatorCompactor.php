<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\StringNode;

use function count;
use function in_array;

final class SlashOperatorCompactor
{
    /**
     * @param array<int, AstNode> $nodes
     * @param list<string> $items
     * @return array<int, string>
     */
    public static function compact(array $nodes, array $items): array
    {
        $count = count($items);

        if ($count < 3) {
            return $items;
        }

        $isOperator = array_map(fn($node): bool => $node instanceof StringNode
            && ! $node->quoted
            && $node->isSlashOperator
            && $node->value === '/', $nodes);

        if (! in_array(true, $isOperator, true)) {
            return $items;
        }

        $result   = [];
        $previous = null;

        foreach ($items as $index => $item) {
            if ($previous !== null) {
                if ($isOperator[$index] || $isOperator[$previous]) {
                    $result[count($result) - 1] .= $item;
                } else {
                    $result[] = $item;
                }
            } else {
                $result[] = $item;
            }

            $previous = $index;
        }

        return $result;
    }
}
