<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Runtime\Environment;
use Closure;

final readonly class ClosureFunctionConditionEvaluator implements FunctionConditionEvaluatorInterface
{
    /** @param Closure(string, Environment): bool $evaluate */
    public function __construct(private Closure $evaluate) {}

    public function evaluate(string $condition, Environment $env): bool
    {
        return ($this->evaluate)($condition, $env);
    }
}
