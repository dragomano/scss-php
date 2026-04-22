<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerRuntime;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Runtime\Environment;

final readonly class ModuleVariableAssigner implements ModuleVariableAssignerInterface
{
    public function __construct(private CompilerRuntime $runtime) {}

    public function assign(ModuleVarDeclarationNode $node, Environment $env): void
    {
        $this->runtime->module()->assignModuleVariable($node, $env);
    }
}
