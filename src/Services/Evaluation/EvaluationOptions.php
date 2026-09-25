<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services\Evaluation;

final class EvaluationOptions
{
    private static ?self $default = null;

    private static ?self $skipSlashArithmeticInstance = null;

    private static ?self $skipConcatenationInstance = null;

    public function __construct(
        public readonly bool $skipSlashArithmetic = false,
        public readonly bool $skipConcatenation = false,
    ) {}

    public static function default(): self
    {
        return self::$default ??= new self();
    }

    public function withSkipSlashArithmetic(): self
    {
        return self::$skipSlashArithmeticInstance ??= new self(skipSlashArithmetic: true);
    }

    public function withSkipConcatenation(): self
    {
        return self::$skipConcatenationInstance ??= new self(skipConcatenation: true);
    }
}
