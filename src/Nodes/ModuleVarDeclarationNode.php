<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;

final class ModuleVarDeclarationNode extends StatementNode
{
    public function __construct(
        public string $module,
        public string $name,
        public AstNode $value,
        public bool $default = false,
    ) {}

    public function accept(Visitor $visitor, TraversalContext $ctx): string
    {
        return $visitor->visitModuleVarDeclaration($this, $ctx);
    }
}
