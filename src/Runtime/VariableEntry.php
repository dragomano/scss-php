<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

final readonly class VariableEntry
{
    public function __construct(public mixed $value, public int $line = 1) {}
}
