<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Stringable;

use function is_float;
use function is_infinite;
use function is_nan;

final class NumberNode extends AstNode implements Stringable
{
    public function __construct(
        public float|int $value,
        public ?string $unit = null,
        public bool $isLiteral = true,
    ) {}

    public function __toString(): string
    {
        if (is_float($this->value)) {
            if (is_nan($this->value)) {
                return 'NaN';
            }

            if (is_infinite($this->value)) {
                return $this->value < 0 ? '-infinity' : 'infinity';
            }
        }

        return "$this->value" . ($this->unit ?? '');
    }
}
