<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class AtRootNode extends StatementNode
{
    /**
     * @param array<int, AstNode> $body
     * @param array<int, string> $queryRules
     */
    public function __construct(
        public array $body = [],
        public ?string $queryMode = null,
        public array $queryRules = []
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitAtRoot($this, $ctx);
    }
}
