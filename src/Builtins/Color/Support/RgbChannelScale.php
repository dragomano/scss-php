<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\Iris\Converters\NormalizedRgbChannels;
use Bugo\Iris\Spaces\RgbColor;

use function max;
use function min;

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

    public static function toNormalizedChannels(RgbColor $rgb, ?float $alpha = null): NormalizedRgbChannels
    {
        $r = $rgb->rValue() / 255.0;
        $g = $rgb->gValue() / 255.0;
        $b = $rgb->bValue() / 255.0;

        $max   = max($r, $g, $b);
        $min   = min($r, $g, $b);
        $delta = $max - $min;

        return new NormalizedRgbChannels(
            r: $r,
            g: $g,
            b: $b,
            a: $alpha ?? $rgb->a,
            max: $max,
            min: $min,
            delta: $delta,
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
