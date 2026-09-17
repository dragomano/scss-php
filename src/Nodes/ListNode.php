<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

/**
 * @phpstan-import-type NodeList from AstNode
 *
 * @psalm-import-type NodeList from AstNode
 */
final class ListNode extends AstNode
{
    /**
     * @param NodeList $items
     */
    public function __construct(
        public array $items = [],
        public string $separator = 'space',
        public bool $bracketed = false,
        public int $parenthesized = 0,
        public bool $isComputed = false,
    ) {}
}
