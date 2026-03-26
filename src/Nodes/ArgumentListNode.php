<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class ArgumentListNode extends AstNode
{
    /**
     * @param array<int, AstNode> $items
     * @param array<string, AstNode> $keywords
     */
    public function __construct(
        public array $items = [],
        public string $separator = 'comma',
        public bool $bracketed = false,
        public array $keywords = []
    ) {}
}
