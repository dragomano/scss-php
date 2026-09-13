<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

/**
 * @phpstan-import-type NodeList from AstNode
 *
 * @psalm-import-type NodeList from AstNode
 */
final class IfNode extends StatementNode
{
    /**
     * @param NodeList $body
     * @param array<int, ElseIfNode> $elseIfBranches
     * @param NodeList $elseBody
     */
    public function __construct(
        public string $condition,
        public array $body,
        public array $elseIfBranches = [],
        public array $elseBody = [],
        public int $line = 1,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitIf($this, $ctx);
    }
}
