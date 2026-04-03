<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Adapters;

use Bugo\Iris\Converters\ModelConverter;
use Bugo\Iris\Converters\NormalizedRgbChannels;
use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\SpaceRouter;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\OklabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;

final readonly class IrisConverterAdapter implements ColorConverterInterface
{
    public function __construct(
        private SpaceConverter $spaceConverter = new SpaceConverter(),
        private SpaceRouter $spaceRouter = new SpaceRouter(),
        private ModelConverter $modelConverter = new ModelConverter(),
    ) {}

    public function clamp(?float $value, float $max): float
    {
        return $this->spaceConverter->clamp($value, $max);
    }

    public function normalizeHue(float $hue): float
    {
        return $this->spaceConverter->normalizeHue($hue);
    }

    public function trimFloat(float $value, int $precision = 6): string
    {
        return $this->spaceConverter->trimFloat($value, $precision);
    }

    public function roundFloat(float $value, int $precision = 6): float
    {
        return $this->spaceConverter->roundFloat($value, $precision);
    }

    public function rgbToXyzD65(RgbColor $rgb): XyzColor
    {
        return $this->spaceConverter->rgbToXyzD65($rgb);
    }

    public function rgbToXyzD50(RgbColor $rgb): XyzColor
    {
        return $this->spaceConverter->rgbToXyzD50($rgb);
    }

    public function rgbToHslColor(RgbColor $rgb): HslColor
    {
        return $this->modelConverter->rgbToHslColor($rgb);
    }

    public function hslToRgb(float $hueDegrees, float $saturation, float $lightness): array
    {
        return $this->spaceConverter->hslToRgb($hueDegrees, $saturation, $lightness);
    }

    public function hwbToRgb(float $hueDegrees, float $whiteness, float $blackness): array
    {
        return $this->spaceConverter->hwbToRgb($hueDegrees, $whiteness, $blackness);
    }

    public function hueFromNormalizedRgb(float $r, float $g, float $b, float $max, float $min, float $delta): float
    {
        return $this->spaceConverter->hueFromNormalizedRgb(new NormalizedRgbChannels(
            r: $r,
            g: $g,
            b: $b,
            a: 1.0,
            max: $max,
            min: $min,
            delta: $delta,
        ));
    }

    public function labToXyzD50(float $l, float $a, float $b): XyzColor
    {
        return $this->spaceConverter->labToXyzD50($l, $a, $b);
    }

    public function xyzD65ToD50(float $x, float $y, float $z): array
    {
        return $this->spaceConverter->xyzD65ToD50($x, $y, $z);
    }

    public function xyzD50ToD65(float $x, float $y, float $z): array
    {
        return $this->spaceConverter->xyzD50ToD65($x, $y, $z);
    }

    public function labChannelsToSrgba(float $l, float $a, float $b, float $opacity): RgbColor
    {
        return $this->spaceConverter->labChannelsToSrgba($l, $a, $b, $opacity);
    }

    public function lchChannelsToSrgba(float $l, float $c, float $h, float $opacity): RgbColor
    {
        return $this->spaceConverter->lchChannelsToSrgba($l, $c, $h, $opacity);
    }

    public function oklabChannelsToSrgba(float $l, float $a, float $b, float $opacity): RgbColor
    {
        return $this->spaceConverter->oklabChannelsToSrgba($l, $a, $b, $opacity);
    }

    public function oklchChannelsToSrgba(float $l, float $c, float $h, float $opacity): RgbColor
    {
        return $this->spaceConverter->oklchChannelsToSrgba($l, $c, $h, $opacity);
    }

    public function labToRgbColor(LabColor $lab): RgbColor
    {
        return $this->spaceConverter->labToRgbColor($lab);
    }

    public function rgbToOklch(RgbColor $rgb): OklchColor
    {
        return $this->spaceConverter->rgbToOklch($rgb);
    }

    public function oklchToSrgb(OklchColor $oklch): RgbColor
    {
        return $this->spaceConverter->oklchToSrgb($oklch);
    }

    public function scaleLinear(float $current, float $amountPercent, float $maxValue): float
    {
        return $this->spaceConverter->scaleLinear($current, $amountPercent, $maxValue);
    }

    public function mixChannel(?float $a, ?float $b, float $p): float
    {
        return $this->spaceConverter->mixChannel($a, $b, $p);
    }

    public function xyzD65ToOklch(XyzColor $xyz, float $alpha = 1.0): OklchColor
    {
        return $this->spaceConverter->xyzD65ToOklch($xyz, $alpha);
    }

    public function lchChannelsToXyzD65(float $l, float $c, float $h): XyzColor
    {
        return $this->spaceConverter->lchChannelsToXyzD65($l, $c, $h);
    }

    public function oklabChannelsToXyzD65(float $l, float $a, float $b): XyzColor
    {
        return $this->spaceConverter->oklabChannelsToXyzD65($l, $a, $b);
    }

    public function oklchChannelsToXyzD65(float $l, float $c, float $h): XyzColor
    {
        return $this->spaceConverter->oklchChannelsToXyzD65($l, $c, $h);
    }

    public function xyzD50ToLabColor(XyzColor $xyz, float $alpha = 1.0): LabColor
    {
        return $this->spaceConverter->xyzD50ToLabColor($xyz, $alpha);
    }

    public function xyzD65ToOklabColor(XyzColor $xyz, float $alpha = 1.0): OklabColor
    {
        return $this->spaceConverter->xyzD65ToOklabColor($xyz, $alpha);
    }

    public function srgbToLinearUnclamped(?float $value): float
    {
        return $this->spaceConverter->srgbToLinearUnclamped($value);
    }

    public function xyzD65ToLinearDisplayP3(XyzColor $xyz): array
    {
        return $this->spaceConverter->xyzD65ToLinearDisplayP3($xyz);
    }

    public function xyzD65ToDisplayP3(XyzColor $xyz): array
    {
        return $this->spaceConverter->xyzD65ToDisplayP3($xyz);
    }

    public function xyzD65ToA98Rgb(XyzColor $xyz): array
    {
        return $this->spaceConverter->xyzD65ToA98Rgb($xyz);
    }

    public function xyzD50ToProphotoRgb(XyzColor $xyz): array
    {
        return $this->spaceConverter->xyzD50ToProphotoRgb($xyz);
    }

    public function xyzD65ToRec2020(XyzColor $xyz): array
    {
        return $this->spaceConverter->xyzD65ToRec2020($xyz);
    }

    public function xyzD50ToLch(XyzColor $xyz): LchColor
    {
        return $this->spaceConverter->xyzD50ToLch($xyz);
    }

    public function oklchToLch(OklchColor $oklch): LchColor
    {
        return $this->spaceConverter->oklchToLch($oklch);
    }

    public function rgbToDisplayP3(RgbColor $rgb): array
    {
        return $this->spaceConverter->rgbToDisplayP3($rgb);
    }

    public function rgbToA98Rgb(RgbColor $rgb): array
    {
        return $this->spaceConverter->rgbToA98Rgb($rgb);
    }

    public function rgbToProphotoRgb(RgbColor $rgb): array
    {
        return $this->spaceConverter->rgbToProphotoRgb($rgb);
    }

    public function rgbToRec2020(RgbColor $rgb): array
    {
        return $this->spaceConverter->rgbToRec2020($rgb);
    }

    public function rgbToLch(RgbColor $rgb): LchColor
    {
        return $this->spaceConverter->rgbToLch($rgb);
    }

    public function convertToXyzD65(string $space, float $c1, float $c2, float $c3): XyzColor
    {
        return $this->spaceRouter->convertToXyzD65($space, $c1, $c2, $c3);
    }

    public function convertToRgba(string $space, float $c1, float $c2, float $c3, float $opacity): RgbColor
    {
        return $this->spaceRouter->convertToRgba($space, $c1, $c2, $c3, $opacity);
    }
}
