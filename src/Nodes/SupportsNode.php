<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class SupportsNode extends StatementNode
{
    /**
     * @param array<int, AstNode> $body
     */
    public function __construct(public string $condition, public array $body = []) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitSupports($this, $ctx);
    }
}
