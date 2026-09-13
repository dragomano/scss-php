<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

interface ValueEvaluatorInterface
{
    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode;
}
