<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Closure;

final readonly class ClosureVariableDeclarationApplier implements VariableDeclarationApplierInterface
{
    /** @param Closure(AstNode, Environment): bool $apply */
    public function __construct(private Closure $apply) {}

    public function apply(AstNode $node, Environment $env): bool
    {
        return ($this->apply)($node, $env);
    }
}
