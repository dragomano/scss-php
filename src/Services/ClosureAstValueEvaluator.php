<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Closure;

final readonly class ClosureAstValueEvaluator implements AstValueEvaluatorInterface
{
    /** @param Closure(AstNode, Environment): AstNode $evaluate */
    public function __construct(private Closure $evaluate) {}

    public function evaluate(AstNode $node, Environment $env): AstNode
    {
        return ($this->evaluate)($node, $env);
    }
}
