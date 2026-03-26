<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

final readonly class VariableDefinition
{
    public function __construct(public Scope $scope, public int $line) {}

    public function line(): int
    {
        return $this->line;
    }
}
