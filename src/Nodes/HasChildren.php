<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use function array_keys;
use function is_array;

/**
 * @phpstan-import-type NodeList from AstNode
 *
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

            foreach (array_keys($value) as $key) {
                /** @var mixed $item */
                $item = $value[$key];

                if ($item instanceof AstNode) {
                    $children[] = $item;
                }
            }
        }

        return $children;
    }
}
