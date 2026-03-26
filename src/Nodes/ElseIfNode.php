<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final class ElseIfNode extends AstNode
{
    /**
     * @param array<int, AstNode> $body
     */
    public function __construct(public string $condition, public array $body) {}
}
