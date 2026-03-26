<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

final readonly class ScopedCallableDefinition
{
    public function __construct(public CallableDefinition $definition, public Scope $scope) {}

    public function isCapturedOutsideScope(): bool
    {
        return $this->definition->closureScope !== $this->scope;
    }

    public function line(): int
    {
        return $this->definition->line;
    }
}
