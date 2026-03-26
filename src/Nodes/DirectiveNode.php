<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class DirectiveNode extends StatementNode
{
    /**
     * @param AstNode[] $body
     */
    public function __construct(
        public string $name,
        public string $prelude = '',
        public array $body = [],
        public bool $hasBlock = false
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitDirective($this, $ctx);
    }
}
