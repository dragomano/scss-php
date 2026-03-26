<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class MixinNode extends StatementNode
{
    /**
     * @param array<int, ArgumentNode> $arguments
     * @param array<int, AstNode> $body
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public array $body = [],
        public int $line = 1
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitMixin($this, $ctx);
    }
}
