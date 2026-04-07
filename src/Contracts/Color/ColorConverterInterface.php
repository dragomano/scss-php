<?php

declare(strict_types=1);

namespace Bugo\SCSS\Contracts\Color;

use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\OklabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;

interface ColorConverterInterface
{
    public function clamp(?float $value, float $max): float;

    public function normalizeHue(float $hue): float;

    public function trimFloat(float $value, int $precision = 6): string;

    public function roundFloat(float $value, int $precision = 6): float;

    public function rgbToXyzD65(RgbColor $rgb): XyzColor;

    public function rgbToXyzD50(RgbColor $rgb): XyzColor;

    public function rgbToHslColor(RgbColor $rgb): HslColor;

    /** @return array{0: float, 1: float, 2: float} */
    public function hslToRgb(float $hueDegrees, float $saturation, float $lightness): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function hwbToRgb(float $hueDegrees, float $whiteness, float $blackness): array;

    public function hueFromNormalizedRgb(float $r, float $g, float $b, float $max, float $min, float $delta): float;

    public function labToXyzD50(float $l, float $a, float $b): XyzColor;

    /** @return array{0: float, 1: float, 2: float} */
    public function xyzD65ToD50(float $x, float $y, float $z): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function xyzD50ToD65(float $x, float $y, float $z): array;

    public function labToRgbColor(LabColor $lab): RgbColor;

    public function rgbToOklch(RgbColor $rgb): OklchColor;

    public function oklchToSrgb(OklchColor $oklch): RgbColor;

    public function scaleLinear(float $current, float $amountPercent, float $maxValue): float;

    public function mixChannel(?float $a, ?float $b, float $p): float;

    public function xyzD65ToOklch(XyzColor $xyz, float $alpha = 1.0): OklchColor;

    public function lchChannelsToXyzD65(float $l, float $c, float $h): XyzColor;

    public function oklabChannelsToXyzD65(float $l, float $a, float $b): XyzColor;

    public function oklchChannelsToXyzD65(float $l, float $c, float $h): XyzColor;

    public function xyzD50ToLabColor(XyzColor $xyz, float $alpha = 1.0): LabColor;

    public function xyzD65ToOklabColor(XyzColor $xyz, float $alpha = 1.0): OklabColor;

    public function srgbToLinearUnclamped(?float $value): float;

    /** @return array{0: float, 1: float, 2: float} */
    public function xyzD65ToLinearDisplayP3(XyzColor $xyz): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function xyzD65ToDisplayP3(XyzColor $xyz): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function xyzD65ToA98Rgb(XyzColor $xyz): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function xyzD50ToProphotoRgb(XyzColor $xyz): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function xyzD65ToRec2020(XyzColor $xyz): array;

    public function xyzD50ToLch(XyzColor $xyz): LchColor;

    public function oklchToLch(OklchColor $oklch): LchColor;

    /** @return array{0: float, 1: float, 2: float} */
    public function rgbToDisplayP3(RgbColor $rgb): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function rgbToA98Rgb(RgbColor $rgb): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function rgbToProphotoRgb(RgbColor $rgb): array;

    /** @return array{0: float, 1: float, 2: float} */
    public function rgbToRec2020(RgbColor $rgb): array;

    public function rgbToLch(RgbColor $rgb): LchColor;

    public function convertToXyzD65(string $space, float $c1, float $c2, float $c3): XyzColor;

    public function convertToRgba(string $space, float $c1, float $c2, float $c3, float $opacity): RgbColor;
}
