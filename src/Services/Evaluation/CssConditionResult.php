<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

final readonly class CssConditionResult
{
    public function __construct(public string $expression) {}
}
