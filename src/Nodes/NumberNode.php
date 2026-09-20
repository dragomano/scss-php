<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Stringable;

use function abs;
use function floor;
use function is_float;
use function is_infinite;
use function is_nan;

final class NumberNode extends AstNode implements Stringable
{
    public function __construct(
        public float|int $value,
        public ?string $unit = null,
        public bool $isLiteral = true,
        public int $parenthesized = 0,
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

            if (floor($this->value) === $this->value && abs($this->value) < 1e21) {
                return (int) $this->value . ($this->unit ?? '');
            }
        }

        return "$this->value" . ($this->unit ?? '');
    }
}
