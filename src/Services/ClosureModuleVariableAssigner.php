<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Runtime\Environment;
use Closure;

final readonly class ClosureModuleVariableAssigner implements ModuleVariableAssignerInterface
{
    /** @param Closure(ModuleVarDeclarationNode, Environment): void $assign */
    public function __construct(private Closure $assign) {}

    public function assign(ModuleVarDeclarationNode $node, Environment $env): void
    {
        ($this->assign)($node, $env);
    }
}
