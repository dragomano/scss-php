<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Stringable;

final class StringNode extends AstNode implements Stringable
{
    public function __construct(
        public string $value,
        public bool $quoted = false,
        public int $line = 0,
        public int $column = 0,
    ) {}

    public function __toString(): string
    {
        return $this->quoted ? '"' . $this->value . '"' : $this->value;
    }
}
