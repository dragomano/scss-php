<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Adapters;

use Bugo\Iris\Converters\ModelConverter;
use Bugo\Iris\Manipulators\LegacyManipulator;
use Bugo\Iris\Manipulators\PerceptualManipulator;
use Bugo\Iris\Manipulators\SrgbManipulator;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Contracts\Color\ColorManipulatorInterface;
use Bugo\SCSS\Contracts\Color\ColorValueInterface;
use LogicException;

final readonly class IrisColorManipulator implements ColorManipulatorInterface
{
    public function __construct(
        private LegacyManipulator $legacy = new LegacyManipulator(),
        private PerceptualManipulator $perceptual = new PerceptualManipulator(),
        private SrgbManipulator $srgb = new SrgbManipulator(),
        private ModelConverter $model = new ModelConverter(),
    ) {}

    /** @return IrisColorValue<HslColor> */
    public function grayscale(ColorValueInterface $hsl): IrisColorValue
    {
        return $this->wrap($this->legacy->grayscale($this->requireHsl($hsl)));
    }

    /** @return IrisColorValue<RgbColor> */
    public function mix(ColorValueInterface $first, ColorValueInterface $second, float $weight): IrisColorValue
    {
        return $this->wrap($this->legacy->mix($this->requireRgb($first), $this->requireRgb($second), $weight));
    }

    /** @return IrisColorValue<RgbColor> */
    public function invert(ColorValueInterface $rgb, float $weight): IrisColorValue
    {
        return $this->wrap($this->legacy->invert($this->requireRgb($rgb), $weight));
    }

    /**
     * @param array<string, float|null> $scales
     * @return IrisColorValue<RgbColor>
     */
    public function scaleColor(ColorValueInterface $color, array $scales): IrisColorValue
    {
        $inner = $this->requireRgb($color);

        return $this->wrap($this->legacy->scale($inner, $this->rgbToHsl($inner), $scales));
    }

    /**
     * @param array<string, float|null> $adjustments
     * @return IrisColorValue<RgbColor>
     */
    public function adjustColor(ColorValueInterface $color, array $adjustments): IrisColorValue
    {
        $inner = $this->requireRgb($color);

        return $this->wrap($this->legacy->adjust($inner, $this->rgbToHsl($inner), $adjustments));
    }

    /**
     * @param array<string, float|null> $changes
     * @return IrisColorValue<RgbColor>
     */
    public function changeColor(ColorValueInterface $color, array $changes): IrisColorValue
    {
        $inner = $this->requireRgb($color);

        return $this->wrap($this->legacy->change($inner, $this->rgbToHsl($inner), $changes));
    }

    /** @return IrisColorValue<RgbColor> */
    public function spin(ColorValueInterface $color, float $degrees): IrisColorValue
    {
        return $this->wrap($this->legacy->spin($this->requireRgb($color), $degrees));
    }

    /** @return IrisColorValue<RgbColor> */
    public function lighten(ColorValueInterface $color, float $amount): IrisColorValue
    {
        return $this->wrap($this->legacy->lighten($this->requireRgb($color), $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function darken(ColorValueInterface $color, float $amount): IrisColorValue
    {
        return $this->wrap($this->legacy->darken($this->requireRgb($color), $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function saturate(ColorValueInterface $color, float $amount): IrisColorValue
    {
        return $this->wrap($this->legacy->saturate($this->requireRgb($color), $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function desaturate(ColorValueInterface $color, float $amount): IrisColorValue
    {
        return $this->wrap($this->legacy->desaturate($this->requireRgb($color), $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function fadeIn(ColorValueInterface $color, float $amount): IrisColorValue
    {
        return $this->wrap($this->legacy->fadeIn($this->requireRgb($color), $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function fadeOut(ColorValueInterface $color, float $amount): IrisColorValue
    {
        return $this->wrap($this->legacy->fadeOut($this->requireRgb($color), $amount));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<OklchColor>
     */
    public function changeOklch(ColorValueInterface $color, array $values): IrisColorValue
    {
        return $this->wrap($this->perceptual->changeOklch($this->requireOklch($color), $values));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<OklchColor>
     */
    public function adjustOklch(ColorValueInterface $color, array $values): IrisColorValue
    {
        return $this->wrap($this->perceptual->adjustOklch($this->requireOklch($color), $values));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<LabColor>
     */
    public function changeLab(ColorValueInterface $color, array $values): IrisColorValue
    {
        return $this->wrap($this->perceptual->changeLab($this->requireLab($color), $values));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<LabColor>
     */
    public function adjustLab(ColorValueInterface $color, array $values): IrisColorValue
    {
        return $this->wrap($this->perceptual->adjustLab($this->requireLab($color), $values));
    }

    public function changeSrgb(float $r, float $g, float $b, array $values): array
    {
        return $this->srgb->change($r, $g, $b, $values);
    }

    public function adjustSrgb(float $r, float $g, float $b, array $values): array
    {
        return $this->srgb->adjust($r, $g, $b, $values);
    }

    /** @return IrisColorValue<RgbColor> */
    public function hslToRgb(ColorValueInterface $hsl): IrisColorValue
    {
        return $this->wrap($this->model->hslToRgbColor($this->requireHsl($hsl)));
    }

    /** @param ColorValueInterface<object> $color */
    private function requireRgb(ColorValueInterface $color): RgbColor
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $inner;
    }

    /** @param ColorValueInterface<object> $color */
    private function requireHsl(ColorValueInterface $color): HslColor
    {
        $inner = $color->getInner();

        if (! $inner instanceof HslColor) {
            throw new LogicException('Expected HslColor inside IrisColorValue, got ' . $inner::class);
        }

        return $inner;
    }

    /** @param ColorValueInterface<object> $color */
    private function requireOklch(ColorValueInterface $color): OklchColor
    {
        $inner = $color->getInner();

        if (! $inner instanceof OklchColor) {
            throw new LogicException('Expected OklchColor inside IrisColorValue, got ' . $inner::class);
        }

        return $inner;
    }

    /** @param ColorValueInterface<object> $color */
    private function requireLab(ColorValueInterface $color): LabColor
    {
        $inner = $color->getInner();

        if (! $inner instanceof LabColor) {
            throw new LogicException('Expected LabColor inside IrisColorValue, got ' . $inner::class);
        }

        return $inner;
    }

    private function rgbToHsl(RgbColor $rgb): HslColor
    {
        return $this->model->rgbToHslColor($rgb);
    }

    /**
     * @template TInner of \Bugo\Iris\Contracts\ColorValueInterface
     * @param TInner $inner
     * @return IrisColorValue<TInner>
     */
    private function wrap(\Bugo\Iris\Contracts\ColorValueInterface $inner): IrisColorValue
    {
        return new IrisColorValue($inner);
    }
}
