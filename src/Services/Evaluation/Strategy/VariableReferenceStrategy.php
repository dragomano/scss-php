<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Bugo\SCSS\Services\Evaluation\ValueEvaluatorInterface;
use Closure;

final readonly class VariableReferenceStrategy implements EvaluationStrategyInterface
{
    /**
     * @param Closure(string, Environment): AstNode $resolveVariable
     */
    public function __construct(
        private ValueEvaluatorInterface $evaluateValue,
        private Closure $resolveVariable,
    ) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof VariableReferenceNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var VariableReferenceNode $node */
        return $this->evaluateValue->evaluate(($this->resolveVariable)($node->name, $env), $env, $options);
    }
}
