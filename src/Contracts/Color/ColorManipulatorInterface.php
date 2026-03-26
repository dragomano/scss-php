<?php

declare(strict_types=1);

namespace Bugo\SCSS\Contracts\Color;

interface ColorManipulatorInterface
{
    /**
     * @param ColorValueInterface<object> $hsl
     * @return ColorValueInterface<object>
     */
    public function grayscale(ColorValueInterface $hsl): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $first
     * @param ColorValueInterface<object> $second
     * @return ColorValueInterface<object>
     */
    public function mix(ColorValueInterface $first, ColorValueInterface $second, float $weight): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $rgb
     * @return ColorValueInterface<object>
     */
    public function invert(ColorValueInterface $rgb, float $weight): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @param array<string, float|null> $scales
     * @return ColorValueInterface<object>
     */
    public function scaleColor(ColorValueInterface $color, array $scales): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @param array<string, float|null> $adjustments
     * @return ColorValueInterface<object>
     */
    public function adjustColor(ColorValueInterface $color, array $adjustments): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @param array<string, float|null> $changes
     * @return ColorValueInterface<object>
     */
    public function changeColor(ColorValueInterface $color, array $changes): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @return ColorValueInterface<object>
     */
    public function spin(ColorValueInterface $color, float $degrees): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @return ColorValueInterface<object>
     */
    public function lighten(ColorValueInterface $color, float $amount): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @return ColorValueInterface<object>
     */
    public function darken(ColorValueInterface $color, float $amount): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @return ColorValueInterface<object>
     */
    public function saturate(ColorValueInterface $color, float $amount): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @return ColorValueInterface<object>
     */
    public function desaturate(ColorValueInterface $color, float $amount): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @return ColorValueInterface<object>
     */
    public function fadeIn(ColorValueInterface $color, float $amount): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @return ColorValueInterface<object>
     */
    public function fadeOut(ColorValueInterface $color, float $amount): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @param array<string, float|null> $values
     * @return ColorValueInterface<object>
     */
    public function changeOklch(ColorValueInterface $color, array $values): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @param array<string, float|null> $values
     * @return ColorValueInterface<object>
     */
    public function adjustOklch(ColorValueInterface $color, array $values): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @param array<string, float|null> $values
     * @return ColorValueInterface<object>
     */
    public function changeLab(ColorValueInterface $color, array $values): ColorValueInterface;

    /**
     * @param ColorValueInterface<object> $color
     * @param array<string, float|null> $values
     * @return ColorValueInterface<object>
     */
    public function adjustLab(ColorValueInterface $color, array $values): ColorValueInterface;

    /**
     * @param array<string, float|null> $values
     * @return array{0: float, 1: float, 2: float}
     */
    public function changeSrgb(float $r, float $g, float $b, array $values): array;

    /**
     * @param array<string, float|null> $values
     * @return array{0: float, 1: float, 2: float}
     */
    public function adjustSrgb(float $r, float $g, float $b, array $values): array;

    /**
     * @param ColorValueInterface<object> $hsl
     * @return ColorValueInterface<object>
     */
    public function hslToRgb(ColorValueInterface $hsl): ColorValueInterface;
}
