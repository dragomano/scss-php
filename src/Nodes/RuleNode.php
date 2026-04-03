<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class RuleNode extends StatementNode
{
    /**
     * @param array<int, AstNode> $children
     */
    public function __construct(
        public string $selector,
        public array $children = [],
        public int $line = 1,
        public int $column = 1,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitRule($this, $ctx);
    }
}
