<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation\Strategy;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\Evaluation\EvaluationOptions;
use Bugo\SCSS\Services\Evaluation\EvaluationStrategyInterface;
use Bugo\SCSS\Services\Evaluation\ValueEvaluatorInterface;

final readonly class NamedArgumentNodeStrategy implements EvaluationStrategyInterface
{
    public function __construct(private ValueEvaluatorInterface $evaluateValue) {}

    public function supports(AstNode $node): bool
    {
        return $node instanceof NamedArgumentNode;
    }

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        /** @var NamedArgumentNode $node */
        $value = $this->evaluateValue->evaluate($node->value, $env, EvaluationOptions::default());

        if ($value === $node->value) {
            return $node;
        }

        return new NamedArgumentNode($node->name, $value);
    }
}
