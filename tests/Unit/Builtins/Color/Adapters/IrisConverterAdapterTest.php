<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;

describe('IrisConverterAdapter', function () {
    beforeEach(function () {
        $this->converter = new IrisConverterAdapter();
    });

    it('converts lab and lch channels to xyz and srgba values', function () {
        $labXyz = $this->converter->labToXyzD50(50.0, 10.0, 20.0);
        $labRgb = $this->converter->labChannelsToSrgba(50.0, 10.0, 20.0, 0.5);
        $lchRgb = $this->converter->lchChannelsToSrgba(50.0, 30.0, 45.0, 0.25);

        expect($labXyz)->toBeInstanceOf(XyzColor::class)
            ->and($labRgb)->toBeInstanceOf(RgbColor::class)
            ->and($labRgb->a)->toBe(0.5)
            ->and($lchRgb)->toBeInstanceOf(RgbColor::class)
            ->and($lchRgb->a)->toBe(0.25);
    });

    it('converts xyz and oklab values through intermediate spaces', function () {
        $xyz = new XyzColor(0.2, 0.3, 0.4);

        $oklabXyz = $this->converter->oklabChannelsToXyzD65(0.6, 0.1, -0.1);
        $lab = $this->converter->xyzD50ToLabColor($xyz, 0.7);
        $displayP3 = $this->converter->xyzD65ToLinearDisplayP3($xyz);
        $a98 = $this->converter->xyzD65ToA98Rgb($xyz);
        $prophoto = $this->converter->xyzD50ToProphotoRgb($xyz);
        $rec2020 = $this->converter->xyzD65ToRec2020($xyz);

        expect($oklabXyz)->toBeInstanceOf(XyzColor::class)
            ->and($lab->alpha)->toBe(0.7)
            ->and($displayP3)->toHaveCount(3)
            ->and($a98)->toHaveCount(3)
            ->and($prophoto)->toHaveCount(3)
            ->and($rec2020)->toHaveCount(3);
    });

    it('converts rgb colors to wide gamut arrays and lch', function () {
        $rgb = new RgbColor(255.0, 128.0, 64.0, 1.0);

        $a98 = $this->converter->rgbToA98Rgb($rgb);
        $prophoto = $this->converter->rgbToProphotoRgb($rgb);
        $rec2020 = $this->converter->rgbToRec2020($rgb);
        $lch = $this->converter->rgbToLch($rgb);

        expect($a98)->toHaveCount(3)
            ->and($prophoto)->toHaveCount(3)
            ->and($rec2020)->toHaveCount(3)
            ->and($lch)->toBeInstanceOf(LchColor::class);
    });
});
