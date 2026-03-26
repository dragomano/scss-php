<?php

declare(strict_types=1);

namespace Bugo\SCSS\Contracts\Color;

interface ColorLiteralInterface
{
    /** @return ColorValueInterface<object>|null */
    public function parse(string $css): ?ColorValueInterface;

    /** @param ColorValueInterface<object> $color */
    public function serialize(ColorValueInterface $color): string;
}
