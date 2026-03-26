<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

/**
 * @phpstan-type Pair array{key: AstNode, value: AstNode}
 * @psalm-type Pair = array{key: AstNode, value: AstNode}
 */
final class MapNode extends AstNode
{
    /**
     * @param array<int, Pair> $pairs
     */
    public function __construct(public array $pairs = []) {}
}
