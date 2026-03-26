<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class DeclarationNode extends StatementNode
{
    public function __construct(
        public string $property,
        public AstNode $value,
        public int $line = 1,
        public int $column = 1,
        public bool $important = false
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitDeclaration($this, $ctx);
    }
}
