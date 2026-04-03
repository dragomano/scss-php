<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;

final class AstValueTransformer
{
    /**
     * @param callable(AstNode): AstNode $transform
     */
    public static function map(AstNode $node, callable $transform): AstNode
    {
        if ($node instanceof ListNode) {
            $items = [];

            foreach ($node->items as $item) {
                $items[] = self::map($item, $transform);
            }

            return $transform(new ListNode($items, $node->separator, $node->bracketed));
        }

        if ($node instanceof ArgumentListNode) {
            $items    = [];
            $keywords = [];

            foreach ($node->items as $item) {
                $items[] = self::map($item, $transform);
            }

            foreach ($node->keywords as $name => $keywordValue) {
                $keywords[$name] = self::map($keywordValue, $transform);
            }

            return $transform(new ArgumentListNode(
                $items,
                $node->separator,
                $node->bracketed,
                $keywords,
            ));
        }

        if ($node instanceof MapNode) {
            $pairs = [];

            foreach ($node->pairs as $pair) {
                $pairs[] = [
                    'key'   => self::map($pair['key'], $transform),
                    'value' => self::map($pair['value'], $transform),
                ];
            }

            return $transform(new MapNode($pairs));
        }

        if ($node instanceof NamedArgumentNode) {
            return $transform(new NamedArgumentNode(
                $node->name,
                self::map($node->value, $transform),
            ));
        }

        if ($node instanceof FunctionNode) {
            $arguments = [];

            foreach ($node->arguments as $argument) {
                $arguments[] = self::map($argument, $transform);
            }

            return $transform(new FunctionNode(
                $node->name,
                $arguments,
                $node->line,
                $node->modernSyntax,
                $node->capturedScope,
            ));
        }

        return $transform($node);
    }
}
