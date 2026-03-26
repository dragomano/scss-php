<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class EachNode extends StatementNode
{
    /**
     * @param array<int, string> $variables
     * @param array<int, AstNode> $body
     */
    public function __construct(
        public array $variables,
        public AstNode $list,
        public array $body = []
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitEach($this, $ctx);
    }
}
