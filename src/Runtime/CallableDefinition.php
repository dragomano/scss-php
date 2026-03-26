<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;

final readonly class CallableDefinition
{
    /**
     * @param array<int, ArgumentNode> $arguments
     * @param array<int, AstNode> $body
     */
    public function __construct(
        public array $arguments,
        public array $body,
        public Scope $closureScope,
        public int $line
    ) {}
}
