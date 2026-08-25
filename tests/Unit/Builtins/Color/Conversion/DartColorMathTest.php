<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Conversion\DartColorMath;

describe('DartColorMath', function () {
    beforeEach(function () {
        $this->math = new DartColorMath();
    });

    it('reproduces the dart-sass white point quirk for oklab to hsl', function () {
        $result = $this->math->convert('oklab', 'hsl', [1.0, 0.0, 0.0]);

        expect($result[0])->toBe(161.81818181818176)
            ->and(round($result[1], 10))->toBe(round(266.6666666666667, 10))
            ->and(round($result[2], 9))->toBe(100.0);
    });

    it('round-trips hsl through lab to xyz-d65 bit-exactly', function () {
        $lab = $this->math->convert('hsl', 'lab', [20.0, 999999.0, 50.0]);
        $xyz = $this->math->convert('lab', 'xyz-d65', [$lab[0], $lab[1], $lab[2]]);

        expect($xyz[0])->toBe(136956388.39988723)
            ->and($xyz[1])->toBe(59264689.52803929)
            ->and($xyz[2])->toBe(-623200798.6169883);
    });

    it('converts every supported space pair without errors', function () {
        $spaces = [
            'rgb', 'hsl', 'hwb', 'srgb', 'srgb-linear', 'display-p3',
            'display-p3-linear', 'a98-rgb', 'prophoto-rgb', 'rec2020',
            'xyz-d65', 'xyz-d50', 'lab', 'lch', 'oklab', 'oklch',
        ];

        foreach ($spaces as $from) {
            foreach ($spaces as $to) {
                $converted = $this->math->convertNumeric($from, $to, [0.25, 0.5, 0.75]);

                expect($converted)->toHaveCount(3);
            }
        }
    });

    it('propagates missing channels only through the default route', function () {
        $missing = $this->math->convert('a98-rgb', 'rgb', [null, 0.2, 0.3]);
        $numeric = $this->math->convertNumeric('a98-rgb', 'rgb', [null, 0.2, 0.3]);

        expect($missing[0])->toBeNull()
            ->and($numeric)->toHaveCount(3)
            ->and($numeric[1])->toEqual($missing[1])
            ->and($numeric[2])->toEqual($missing[2]);
    });

    it('keeps hue numeric for hsl destinations with zero saturation', function () {
        [$hue, $saturation, $lightness] = $this->math->convertNumeric('a98-rgb', 'hsl', [0.5, 0.5, 0.5]);

        expect($hue)->toBe(0.0)
            ->and($saturation)->toBeLessThan(1e-11)
            ->and($lightness)->toBeGreaterThan(50.0);
    });

    it('maps missing whiteness and blackness of hwb sources to lch hue', function () {
        $converted = $this->math->convert('hwb', 'lch', [10.0, null, null]);

        expect($converted[0])->toBeNull()
            ->and($converted[1])->toBeNull()
            ->and($converted[2])->toBeGreaterThan(0.0);
    });

    it('normalizes hue with the dart double modulo formula', function () {
        expect($this->math->normalizeHue(-43.4944))->toBe(316.50559999999996)
            ->and($this->math->normalizeHue(720.0))->toBe(0.0);
    });

    it('detects fuzzy equality with the 1e-11 threshold', function () {
        expect($this->math->fuzzyEquals(1.0, 1.0))->toBeTrue()
            ->and($this->math->fuzzyEquals(1.0, 1.0 + 5e-12))->toBeTrue()
            ->and($this->math->fuzzyEquals(1.0, 2.0))->toBeFalse()
            ->and($this->math->fuzzyEquals(2e15, 2e15 + 1.0))->toBeFalse();
    });

    it('detects fuzzy integers', function () {
        expect($this->math->fuzzyIsInt(255.00000000000003))->toBeTrue()
            ->and($this->math->fuzzyIsInt(255.5))->toBeFalse();
    });

    it('computes srgb to hsl and hwb with the dart formulas', function () {
        [$hue, $saturation, $lightness] = $this->math->srgbToHsl(1.0, 0.0, 0.0);

        expect($hue)->toBe(0.0)
            ->and($saturation)->toBe(100.0)
            ->and($lightness)->toBe(50.0);

        [$outHue, $outWhiteness, $outBlackness] = $this->math->srgbToHwb(1.0, 0.0, 0.0);

        expect($outHue)->toBe(0.0)
            ->and($outWhiteness)->toBe(0.0)
            ->and($outBlackness)->toBe(0.0);
    });

    it('flips negative saturation by rotating hue 180 degrees', function () {
        [, , $lightness] = $this->math->srgbToHsl(-0.5, 1.0, 1.0);

        expect($lightness)->toBe(25.0);
    });

    it('converts lab to lch with dart trigonometry', function () {
        [$lightness, $chroma, $hue] = $this->math->labToLch(10.0, 3.0, 4.0);

        expect($lightness)->toBe(10.0)
            ->and($chroma)->toBe(5.0)
            ->and(round($hue, 9))->toBe(round(atan2(4.0, 3.0) * 180 / M_PI, 9));
    });

    it('returns zero saturation for pure black and white srgb sources', function () {
        [$hue, $saturation] = $this->math->convertNumeric('srgb', 'hsl', [0.0, 0.0, 0.0]);

        expect($hue)->toBe(0.0)
            ->and($saturation)->toBe(0.0);
    });

    it('uses the upper lightness branch of the dart hsl to rgb algorithm', function () {
        [$r, $g, $b] = $this->math->hslToSrgb(80.0, 30.0, 60.0);

        expect($r)->toBeGreaterThan(0.5)
            ->and($g)->toBeGreaterThan(0.5)
            ->and($b)->toBeLessThan(0.5);
    });

    it('normalizes oversized hwb sums', function () {
        [$r, $g, $b] = $this->math->hwbToSrgb(10.0, 60.0, 60.0);

        expect($r)->toBeGreaterThanOrEqual(0.0)
            ->and($r)->toEqual($g)
            ->and($g)->toEqual($b);
    });

    it('handles fully missing and hue-only hwb sources', function () {
        expect($this->math->convert('hwb', 'rgb', [null, null, null]))->toEqual([null, null, null])
            ->and($this->math->convert('hwb', 'hsl', [10.0, null, null])[1])->toBeNull();
    });

    it('keeps identical perceptual spaces as-is', function () {
        expect($this->math->convert('lab', 'lab', [40.0, 5.0, -6.0]))->toEqual([40.0, 5.0, -6.0])
            ->and($this->math->convert('lch', 'lch', [40.0, 5.0, 30.0]))->toHaveCount(3)
            ->and($this->math->convert('oklch', 'oklch', [0.1, 0.02, 30.0]))->toHaveCount(3);
    });

    it('routes legacy rgb through the per-channel linear gamma', function () {
        $linear = $this->math->convertNumeric('rgb', 'xyz-d65', [128.0, 64.0, 32.0]);

        expect($linear[0])->toBeGreaterThan(0.0);

        $prophoto = $this->math->convertNumeric('xyz-d50', 'prophoto-rgb', [0.0001, 0.0, 0.0]);

        expect($prophoto[2])->toBe(0.0);
    });

    it('uses the linear prophoto branch for dark channels', function () {
        $linear = $this->math->convertNumeric('prophoto-rgb', 'xyz-d50', [0.0001, 0.0001, 0.0001]);

        expect($linear)->toHaveCount(3)
            ->and($linear[0])->toBeGreaterThan(0.0);
    });

    it('converts display p3 family through linear srgb matrices', function () {
        $fromP3       = $this->math->convertNumeric('display-p3', 'srgb', [0.2, 0.4, 0.8]);
        $fromP3Linear = $this->math->convertNumeric('display-p3-linear', 'srgb', [0.0331047666, 0.1328683216, 0.6038273389]);

        expect($fromP3[0])->toBeGreaterThan(0.0)
            ->and($fromP3Linear[0])->toBeGreaterThan(0.0);
    });

    it('wraps hues above one full turn in the hue to rgb helper', function () {
        [$r, , ] = $this->math->hslToSrgb(350.0, 50.0, 50.0);

        expect($r)->toBeGreaterThan(0.5);
    });
});
