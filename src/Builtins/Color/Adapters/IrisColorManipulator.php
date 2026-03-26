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
        private ModelConverter $model = new ModelConverter()
    ) {}

    /** @return IrisColorValue<HslColor> */
    public function grayscale(ColorValueInterface $hsl): IrisColorValue
    {
        $inner = $hsl->getInner();

        if (! $inner instanceof HslColor) {
            throw new LogicException('Expected HslColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->grayscale($inner));
    }

    /** @return IrisColorValue<RgbColor> */
    public function mix(ColorValueInterface $first, ColorValueInterface $second, float $weight): IrisColorValue
    {
        $firstInner  = $first->getInner();
        $secondInner = $second->getInner();

        if (! $firstInner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $firstInner::class);
        }

        if (! $secondInner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $secondInner::class);
        }

        return $this->wrap($this->legacy->mix($firstInner, $secondInner, $weight));
    }

    /** @return IrisColorValue<RgbColor> */
    public function invert(ColorValueInterface $rgb, float $weight): IrisColorValue
    {
        $inner = $rgb->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->invert($inner, $weight));
    }

    /**
     * @param array<string, float|null> $scales
     * @return IrisColorValue<RgbColor>
     */
    public function scaleColor(ColorValueInterface $color, array $scales): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->scale($inner, $this->rgbToHsl($inner), $scales));
    }

    /**
     * @param array<string, float|null> $adjustments
     * @return IrisColorValue<RgbColor>
     */
    public function adjustColor(ColorValueInterface $color, array $adjustments): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->adjust($inner, $this->rgbToHsl($inner), $adjustments));
    }

    /**
     * @param array<string, float|null> $changes
     * @return IrisColorValue<RgbColor>
     */
    public function changeColor(ColorValueInterface $color, array $changes): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->change($inner, $this->rgbToHsl($inner), $changes));
    }

    /** @return IrisColorValue<RgbColor> */
    public function spin(ColorValueInterface $color, float $degrees): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->spin($inner, $degrees));
    }

    /** @return IrisColorValue<RgbColor> */
    public function lighten(ColorValueInterface $color, float $amount): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->lighten($inner, $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function darken(ColorValueInterface $color, float $amount): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->darken($inner, $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function saturate(ColorValueInterface $color, float $amount): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->saturate($inner, $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function desaturate(ColorValueInterface $color, float $amount): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->desaturate($inner, $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function fadeIn(ColorValueInterface $color, float $amount): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->fadeIn($inner, $amount));
    }

    /** @return IrisColorValue<RgbColor> */
    public function fadeOut(ColorValueInterface $color, float $amount): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->legacy->fadeOut($inner, $amount));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<OklchColor>
     */
    public function changeOklch(ColorValueInterface $color, array $values): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof OklchColor) {
            throw new LogicException('Expected OklchColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->perceptual->changeOklch($inner, $values));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<OklchColor>
     */
    public function adjustOklch(ColorValueInterface $color, array $values): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof OklchColor) {
            throw new LogicException('Expected OklchColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->perceptual->adjustOklch($inner, $values));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<LabColor>
     */
    public function changeLab(ColorValueInterface $color, array $values): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof LabColor) {
            throw new LogicException('Expected LabColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->perceptual->changeLab($inner, $values));
    }

    /**
     * @param array<string, float|null> $values
     * @return IrisColorValue<LabColor>
     */
    public function adjustLab(ColorValueInterface $color, array $values): IrisColorValue
    {
        $inner = $color->getInner();

        if (! $inner instanceof LabColor) {
            throw new LogicException('Expected LabColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->perceptual->adjustLab($inner, $values));
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
        $inner = $hsl->getInner();

        if (! $inner instanceof HslColor) {
            throw new LogicException('Expected HslColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->wrap($this->model->hslToRgbColor($inner));
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
