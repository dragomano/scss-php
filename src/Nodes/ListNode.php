<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class ListNode extends AstNode
{
    /**
     * @param array<int, AstNode> $items
     */
    public function __construct(
        public array $items = [],
        public string $separator = 'space',
        public bool $bracketed = false
    ) {}
}
