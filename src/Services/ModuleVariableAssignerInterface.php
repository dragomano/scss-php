<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Runtime\Environment;

interface ModuleVariableAssignerInterface
{
    public function assign(ModuleVarDeclarationNode $node, Environment $env): void;
}
