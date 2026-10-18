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

            // Dart Sass serializes numbers rounded to 10 decimal places with
            // trailing zeros stripped; whole numbers print without a point.
            $rounded = round($this->value, 10);

            if (floor($rounded) === $rounded && abs($rounded) < 1e21) {
                return (int) $rounded . ($this->unit ?? '');
            }

            return rtrim(rtrim(sprintf('%.10F', $rounded), '0'), '.') . ($this->unit ?? '');
        }

        return "$this->value" . ($this->unit ?? '');
    }
}
