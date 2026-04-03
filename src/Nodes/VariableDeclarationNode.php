<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class VariableDeclarationNode extends StatementNode
{
    public function __construct(
        public string $name,
        public AstNode $value,
        public bool $global = false,
        public bool $default = false,
        public int $line = 1,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitVariableDeclaration($this, $ctx);
    }
}
