<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\Iris\Converters\NormalizedRgbChannels;
use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\Spaces\RgbColor;

use function abs;
use function max;
use function min;

final readonly class LegacyColorMath
{
    public function __construct(private SpaceConverter $spaceConverter) {}

    /**
     * Precise rgb -> hsl conversion without intermediate rounding or clamping.
     *
     * @return array{h: float, s: float, l: float, a: float}
     */
    public function rgbToHsl(RgbColor $rgb): array
    {
        $r = $rgb->rValue() / 255.0;
        $g = $rgb->gValue() / 255.0;
        $b = $rgb->bValue() / 255.0;

        $max   = max($r, $g, $b);
        $min   = min($r, $g, $b);
        $delta = $max - $min;
        $l     = ($max + $min) / 2.0;

        $h = $this->spaceConverter->hueFromNormalizedRgb(
            new NormalizedRgbChannels(
                r: $r,
                g: $g,
                b: $b,
                a: $rgb->a,
                max: $max,
                min: $min,
                delta: $delta,
            ),
        );

        $denom = 1.0 - abs(2.0 * $l - 1.0);
        $s     = $delta > 0.0 ? $delta / $denom : 0.0;

        return [
            'h' => $this->spaceConverter->normalizeHue($h),
            's' => $s * 100.0,
            'l' => $l * 100.0,
            'a' => $rgb->a,
        ];
    }

    /**
     * Precise hsl -> rgb conversion without clamping saturation or lightness.
     */
    public function hslToRgb(float $hue, float $saturation, float $lightness, float $alpha): RgbColor
    {
        [$r, $g, $b] = $this->spaceConverter->hslToRgb(
            $hue,
            $saturation / 100.0,
            $lightness / 100.0,
        );

        return new RgbColor(r: $r * 255.0, g: $g * 255.0, b: $b * 255.0, a: $alpha);
    }

    /**
     * Shifts an hsl channel by a signed delta, clamping like dart-sass:
     * hue wraps around 360 degrees, alpha is clamped to [0, 1] and
     * saturation/lightness are clamped to [0, 100].
     *
     * @param array{h: float, s: float, l: float, a: float} $hsl
     * @return array{h: float, s: float, l: float, a: float}
     */
    public function shiftChannel(array $hsl, string $channel, float $delta): array
    {
        $value = match ($channel) {
            'h'     => $this->spaceConverter->normalizeHue($hsl[$channel] + $delta),
            'a'     => max(0.0, min(1.0, $hsl[$channel] + $delta)),
            default => max(0.0, min(100.0, $hsl[$channel] + $delta)),
        };

        return [
            'h' => $channel === 'h' ? $value : $hsl['h'],
            's' => $channel === 's' ? $value : $hsl['s'],
            'l' => $channel === 'l' ? $value : $hsl['l'],
            'a' => $channel === 'a' ? $value : $hsl['a'],
        ];
    }
}
