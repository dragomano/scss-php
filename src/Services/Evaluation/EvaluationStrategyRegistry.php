<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

final readonly class EvaluationStrategyRegistry
{
    /** @param list<EvaluationStrategyInterface> $strategies */
    public function __construct(private array $strategies) {}

    public function evaluate(AstNode $node, Environment $env, EvaluationOptions $options): AstNode
    {
        foreach ($this->strategies as $strategy) {
            if ($strategy->supports($node)) {
                return $strategy->evaluate($node, $env, $options);
            }
        }

        return $node;
    }
}
