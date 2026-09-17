<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

/**
 * @phpstan-import-type NodeList from AstNode
 * @phpstan-import-type NodeMap from AstNode
 *
 * @psalm-import-type NodeList from AstNode
 * @psalm-import-type NodeMap from AstNode
 */
final class ArgumentListNode extends AstNode
{
    /**
     * @param NodeList $items
     * @param NodeMap $keywords
     */
    public function __construct(
        public array $items = [],
        public string $separator = 'comma',
        public bool $bracketed = false,
        public array $keywords = [],
    ) {}
}
