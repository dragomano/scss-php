<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

final readonly class EvaluationOptions
{
    public function __construct(
        public bool $skipSlashArithmetic = false,
        public bool $skipConcatenation = false,
    ) {}

    public static function default(): self
    {
        return new self();
    }

    public function withSkipSlashArithmetic(): self
    {
        return new self(skipSlashArithmetic: true);
    }

    public function withSkipConcatenation(): self
    {
        return new self(skipConcatenation: true);
    }
}
