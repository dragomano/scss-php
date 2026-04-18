<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Bugo\SCSS\Services\FunctionCallEvaluator;

final readonly class FunctionNodeStrategy implements EvaluationStrategyInterface
{
    public function __construct(private FunctionCallEvaluator $functionCalls) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof FunctionNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var FunctionNode $node */
        return $this->functionCalls->evaluate($node, $env);
    }
}
