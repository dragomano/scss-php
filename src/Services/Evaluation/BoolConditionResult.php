<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

final readonly class BoolConditionResult
{
    public function __construct(public bool $value) {}
}
