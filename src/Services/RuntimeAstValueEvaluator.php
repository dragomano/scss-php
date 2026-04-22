<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerRuntime;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

final readonly class RuntimeAstValueEvaluator implements AstValueEvaluatorInterface
{
    public function __construct(private CompilerRuntime $runtime) {}

    public function evaluate(AstNode $node, Environment $env): AstNode
    {
        return $this->runtime->evaluation()->evaluateValue($node, $env);
    }
}
