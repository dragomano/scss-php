<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use function array_filter;
use function is_array;

trait HasChildren
{
    /** @var array<int, string> */
    private array $childProperties = ['body', 'children', 'contentBlock', 'elseBody'];

    /** @return array<int, AstNode> */
    public function getChildren(): array
    {
        $children = [];

        foreach ($this->childProperties as $property) {
            $value = $this->$property ?? null;

            if (! is_array($value)) {
                continue;
            }

            foreach (array_filter($value, is_object(...)) as $item) {
                if ($item instanceof AstNode) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }
}
