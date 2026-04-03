<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class IfNode extends StatementNode
{
    /**
     * @param array<int, AstNode> $body
     * @param array<int, ElseIfNode> $elseIfBranches
     * @param array<int, AstNode> $elseBody
     */
    public function __construct(
        public string $condition,
        public array $body,
        public array $elseIfBranches = [],
        public array $elseBody = [],
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitIf($this, $ctx);
    }
}
