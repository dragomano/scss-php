<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Closure;

final readonly class NamedArgumentNodeStrategy implements EvaluationStrategyInterface
{
    /**
     * @param Closure(AstNode, Environment, EvaluationOptions): AstNode $evaluateValue
     */
    public function __construct(private Closure $evaluateValue) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof NamedArgumentNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var NamedArgumentNode $node */
        $value = ($this->evaluateValue)($node->value, $env, EvaluationOptions::default());

        if ($value === $node->value) {
            return $node;
        }

        return new NamedArgumentNode($node->name, $value);
    }
}
