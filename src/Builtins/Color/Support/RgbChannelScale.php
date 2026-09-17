<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\Iris\Spaces\RgbColor;

final class RgbChannelScale
{
    public static function toNormalized(RgbColor $rgb): RgbColor
    {
        return new RgbColor(
            r: $rgb->rValue() / 255.0,
            g: $rgb->gValue() / 255.0,
            b: $rgb->bValue() / 255.0,
            a: $rgb->a,
        );
    }

    public static function toByte(RgbColor $rgb): RgbColor
    {
        return new RgbColor(
            r: $rgb->rValue() * 255.0,
            g: $rgb->gValue() * 255.0,
            b: $rgb->bValue() * 255.0,
            a: $rgb->a,
        );
    }
}
