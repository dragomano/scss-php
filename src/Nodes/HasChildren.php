<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use function array_filter;
use function is_array;

/**
 * @psalm-import-type NodeList from AstNode
 */
trait HasChildren
{
    /** @var array<int, string> */
    private array $childProperties = ['body', 'children', 'contentBlock', 'elseBody'];

    /** @return NodeList */
    public function getChildren(): array
    {
        $children = [];

        foreach ($this->childProperties as $property) {
            /** @var mixed $value */
            $value = $this->$property ?? null;

            if (! is_array($value)) {
                continue;
            }

            foreach (array_filter($value, static fn(mixed $item): bool => $item instanceof AstNode) as $item) {
                $children[] = $item;
            }
        }

        return $children;
    }
}
