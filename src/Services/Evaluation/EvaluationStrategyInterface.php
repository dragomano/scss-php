<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

interface EvaluationStrategyInterface
{
    public function supports(AstNode $node): bool;

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode;
}
