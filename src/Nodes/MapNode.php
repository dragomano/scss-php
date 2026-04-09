<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class MapNode extends AstNode
{
    /**
     * @param list<MapPair> $pairs
     */
    public function __construct(public array $pairs = []) {}
}
