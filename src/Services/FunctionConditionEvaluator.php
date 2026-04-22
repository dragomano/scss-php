<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Runtime\Environment;

final readonly class FunctionConditionEvaluator implements FunctionConditionEvaluatorInterface
{
    public function __construct(private Condition $condition) {}

    public function evaluate(string $condition, Environment $env): bool
    {
        return $this->condition->evaluate($condition, $env);
    }
}
