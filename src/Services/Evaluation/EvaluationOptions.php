<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

final readonly class EvaluationOptions
{
    public function __construct(public bool $skipSlashArithmetic = false) {}

    public static function default(): self
    {
        return new self();
    }

    public function withSkipSlashArithmetic(): self
    {
        return new self(true);
    }
}
