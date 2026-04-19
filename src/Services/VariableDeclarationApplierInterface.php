<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

interface VariableDeclarationApplierInterface
{
    public function apply(AstNode $node, Environment $env): bool;
}
