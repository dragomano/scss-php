<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

final class ConditionCacheState
{
    /** @var array<string, array<int, string>> */
    public array $split = [];

    /** @var array<string, array{0: string, 1: string, 2: string}|null> */
    public array $comparison = [];

    /** @var array<string, mixed> */
    public array $literalValue = [];

    /** @var array<string, mixed> */
    public array $parsed = [];

    public function reset(): void
    {
        $this->split        = [];
        $this->comparison   = [];
        $this->literalValue = [];
        $this->parsed       = [];
    }
}
