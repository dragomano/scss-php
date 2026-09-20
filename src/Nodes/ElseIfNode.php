<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

/**
 * @psalm-import-type NodeList from AstNode
 */
final class ElseIfNode extends AstNode
{
    /**
     * @param NodeList $body
     */
    public function __construct(
        public string $condition,
        public array $body,
        public int $line = 1,
    ) {}
}
