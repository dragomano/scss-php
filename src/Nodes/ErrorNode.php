<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class ErrorNode extends StatementNode implements DiagnosticNode
{
    public function __construct(
        public AstNode $message,
        public int $line = 1,
        public int $column = 1,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        $visitor->visitError($this, $ctx);
    }
}
