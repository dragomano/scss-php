<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisColorManipulator;
use Bugo\SCSS\Builtins\Color\Adapters\IrisColorValue;
use Bugo\SCSS\Contracts\Color\ColorValueInterface;

describe('IrisColorManipulator', function () {
    beforeEach(function () {
        $this->manipulator = new IrisColorManipulator();
        $this->wrap = static fn(object $inner): ColorValueInterface => new class ($inner) implements ColorValueInterface {
            public function __construct(private readonly object $inner) {}

            public function getSpace(): string
            {
                return 'fake';
            }

            public function getChannels(): array
            {
                return [];
            }

            public function getAlpha(): float
            {
                return 1.0;
            }

            public function getInner(): object
            {
                return $this->inner;
            }
        };
    });

    it('throws when operations receive wrapped values from unexpected color spaces', function () {
        $invalid = ($this->wrap)(new stdClass());
        $rgb = ($this->wrap)(new RgbColor(10.0, 20.0, 30.0, 1.0));

        expect(fn() => $this->manipulator->grayscale($invalid))
            ->toThrow(LogicException::class, 'Expected HslColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->mix($invalid, $rgb, 50.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->mix($rgb, $invalid, 50.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->invert($invalid, 20.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->scaleColor($invalid, ['red' => 10.0]))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->adjustColor($invalid, ['red' => 10.0]))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->changeColor($invalid, ['red' => 10.0]))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->spin($invalid, 15.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->lighten($invalid, 10.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->darken($invalid, 10.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->saturate($invalid, 10.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->desaturate($invalid, 10.0))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->fadeIn($invalid, 0.1))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->fadeOut($invalid, 0.1))
            ->toThrow(LogicException::class, 'Expected RgbColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->changeOklch($invalid, ['l' => 0.1]))
            ->toThrow(LogicException::class, 'Expected OklchColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->adjustOklch($invalid, ['c' => 0.1]))
            ->toThrow(LogicException::class, 'Expected OklchColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->changeLab($invalid, ['l' => 5.0]))
            ->toThrow(LogicException::class, 'Expected LabColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->adjustLab($invalid, ['a' => 5.0]))
            ->toThrow(LogicException::class, 'Expected LabColor inside IrisColorValue, got stdClass')
            ->and(fn() => $this->manipulator->hslToRgb($invalid))
            ->toThrow(LogicException::class, 'Expected HslColor inside IrisColorValue, got stdClass');
    });

    it('returns wrapped values for supported Lab and HSL conversions', function () {
        $lab = ($this->wrap)(new LabColor(50.0, 10.0, 5.0, 1.0));
        $changed = $this->manipulator->changeLab($lab, ['l' => 60.0]);

        expect($changed)->toBeInstanceOf(IrisColorValue::class)
            ->and($changed->getInner())->toBeInstanceOf(LabColor::class);

        $hsl = ($this->wrap)(new HslColor(210.0, 50.0, 40.0, 1.0));
        $converted = $this->manipulator->hslToRgb($hsl);

        expect($converted)->toBeInstanceOf(IrisColorValue::class)
            ->and($converted->getInner())->toBeInstanceOf(RgbColor::class);
    });

    it('delegates srgb channel adjustments to the iris manipulator', function () {
        $adjusted = $this->manipulator->adjustSrgb(10.0, 20.0, 30.0, [
            'red' => 5.0,
            'blue' => -10.0,
        ]);

        expect($adjusted)->toBeArray()
            ->and($adjusted)->toHaveCount(3);
    });
});
