<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Runtime\Environment;

interface FunctionConditionEvaluatorInterface
{
    public function evaluate(string $condition, Environment $env): bool;
}
