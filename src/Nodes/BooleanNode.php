<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Stringable;

final class BooleanNode extends AstNode implements Stringable
{
    public function __construct(public readonly bool $value) {}

    public function __toString(): string
    {
        return $this->value ? 'true' : 'false';
    }
}
