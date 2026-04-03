<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\Scope;

final class FunctionNode extends AstNode
{
    /**
     * @param array<int, AstNode> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public int $line = 0,
        public bool $modernSyntax = false,
        public ?Scope $capturedScope = null,
    ) {}
}
