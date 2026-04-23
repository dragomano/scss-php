<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('CssColorFunctionConverter', function () {
    beforeEach(function () {
        $this->converter = new CssColorFunctionConverter();
    });

    it('converts rgb functions to rgba', function () {
        $rgb = $this->converter->tryConvertToRgba(new FunctionNode('rgb', [
            new NumberNode(255),
            new NumberNode(0),
            new NumberNode(0),
        ]));

        expect($rgb)->not->toBeNull()
            ->and($rgb?->r)->toBe(1.0)
            ->and($rgb?->g)->toBe(0.0)
            ->and($rgb?->b)->toBe(0.0)
            ->and($rgb?->a)->toBe(1.0);
    });

    it('converts color(srgb ...) functions to xyz d65', function () {
        $xyz = $this->converter->tryConvertToXyzD65(new FunctionNode('color', [
            new StringNode('srgb'),
            new NumberNode(0.1),
            new NumberNode(0.2),
            new NumberNode(0.3),
        ]));

        expect($xyz)->not->toBeNull()
            ->and($xyz[0]->x)->toBeGreaterThan(0.0)
            ->and($xyz[0]->y)->toBeGreaterThan(0.0)
            ->and($xyz[0]->z)->toBeGreaterThan(0.0)
            ->and($xyz[1])->toBe(1.0);
    });

    it('returns null for unsupported channel layout', function () {
        expect($this->converter->tryConvertToRgba(new FunctionNode('rgb', [new StringNode('oops')])))
            ->toBeNull();
    });

    it('returns null for invalid xyz conversion inputs', function () {
        expect($this->converter->tryConvertToXyzD65(new FunctionNode('lab', [new StringNode('oops')])))
            ->toBeNull()
            ->and($this->converter->tryConvertToXyzD65(new FunctionNode('rgb', [new StringNode('oops')])))
            ->toBeNull()
            ->and($this->converter->tryConvertToXyzD65(new FunctionNode('color', [
                new StringNode('srgb'),
                new NumberNode(0.1),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToXyzD50(new FunctionNode('rgb', [new StringNode('oops')])))
            ->toBeNull();
    });

    it('converts hsl perceptual and generic color functions', function () {
        $hsl = $this->converter->tryConvertToRgba(new FunctionNode('hsl', [new ListNode([
            new NumberNode(0.5, 'turn'),
            new NumberNode(100, '%'),
            new NumberNode(50, '%'),
        ], 'space')]));

        $lab = $this->converter->tryConvertToRgba(new FunctionNode('lab', [
            new NumberNode(50, '%'),
            new NumberNode(10),
            new NumberNode(20),
        ]));

        $lch = $this->converter->tryConvertToRgba(new FunctionNode('lch', [
            new NumberNode(50, '%'),
            new NumberNode(20),
            new NumberNode(180, 'deg'),
        ]));

        $oklabXyz = $this->converter->tryConvertToXyzD65(new FunctionNode('oklab', [
            new NumberNode(50, '%'),
            new NumberNode(0.1),
            new NumberNode(-0.1),
        ]));

        $oklchXyz = $this->converter->tryConvertToXyzD65(new FunctionNode('oklch', [
            new NumberNode(50, '%'),
            new NumberNode(0.1),
            new NumberNode(120, 'deg'),
        ]));

        $xyzD50 = $this->converter->tryConvertToXyzD50(new FunctionNode('lch', [
            new NumberNode(50, '%'),
            new NumberNode(20),
            new NumberNode(180, 'deg'),
        ]));

        $xyzColor = $this->converter->tryConvertToXyzD65(new FunctionNode('color', [
            new StringNode('display-p3'),
            new NumberNode(0.1),
            new NumberNode(0.2),
            new NumberNode(0.3),
        ]));

        expect($hsl)->not->toBeNull()
            ->and($lab)->not->toBeNull()
            ->and($lch)->not->toBeNull()
            ->and($oklabXyz)->not->toBeNull()
            ->and($oklchXyz)->not->toBeNull()
            ->and($xyzD50)->not->toBeNull()
            ->and($xyzColor)->not->toBeNull();
    });

    it('returns null for unsupported or malformed color() functions', function () {
        expect($this->converter->tryConvertToRgba(new FunctionNode('color', [
            new StringNode('unknown-space'),
            new NumberNode(0.1),
            new NumberNode(0.2),
            new NumberNode(0.3),
        ])))->toBeNull()
            ->and($this->converter->tryConvertToXyzD65(new FunctionNode('color', [
                new StringNode('unknown-space'),
                new NumberNode(0.1),
                new NumberNode(0.2),
                new NumberNode(0.3),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('color', [
                new NumberNode(1),
                new NumberNode(0.1),
                new NumberNode(0.2),
                new NumberNode(0.3),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('color', [
                new StringNode('srgb'),
                new NumberNode(0.1, 'px'),
                new NumberNode(0.2),
                new NumberNode(0.3),
            ])))->toBeNull();
    });

    it('returns null for malformed perceptual and generic color functions', function () {
        expect($this->converter->tryConvertToRgba(new FunctionNode('color', [
            new StringNode('srgb'),
            new NumberNode(0.1),
            new NumberNode(0.2),
        ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('lab', [
                new NumberNode(50, '%'),
                new NumberNode(10, 'px'),
                new NumberNode(20),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToXyzD65(new FunctionNode('oklab', [
                new NumberNode(50, '%'),
                new NumberNode(0.1, 'px'),
                new NumberNode(-0.1),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('hwb', [
                new NumberNode(120, 'deg'),
                new NumberNode(10, '%'),
            ])))->toBeNull();
    });

    it('returns null for invalid channel node types and units', function () {
        expect($this->converter->tryConvertToRgba(new FunctionNode('rgb', [
            new StringNode('oops'),
            new NumberNode(0),
            new NumberNode(0),
        ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('rgb', [
                new NumberNode(10, 'px'),
                new NumberNode(0),
                new NumberNode(0),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('hsl', [
                new NumberNode(30, 'px'),
                new NumberNode(100, '%'),
                new NumberNode(50, '%'),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('hsl', [
                new NumberNode(30, 'deg'),
                new NumberNode(100, 'px'),
                new NumberNode(50, '%'),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('lab', [
                new NumberNode(50, 'deg'),
                new NumberNode(10),
                new NumberNode(20),
            ])))->toBeNull()
            ->and($this->converter->tryConvertToRgba(new FunctionNode('rgb', [new ListNode([
                new NumberNode(255),
                new NumberNode(0),
                new NumberNode(0),
                new StringNode('/'),
                new NumberNode(50, 'px'),
            ], 'space')])))->toBeNull();
    });

    it('supports grad and rad hue units', function () {
        $grad = $this->converter->tryConvertToRgba(new FunctionNode('hsl', [
            new NumberNode(200, 'grad'),
            new NumberNode(100, '%'),
            new NumberNode(50, '%'),
        ]));

        $rad = $this->converter->tryConvertToRgba(new FunctionNode('hsl', [
            new NumberNode(M_PI, 'rad'),
            new NumberNode(100, '%'),
            new NumberNode(50, '%'),
        ]));

        expect($grad)->not->toBeNull()
            ->and($grad?->r)->toBeCloseTo(0.0)
            ->and($grad?->g)->toBeCloseTo(1.0)
            ->and($grad?->b)->toBeCloseTo(1.0)
            ->and($rad)->not->toBeNull()
            ->and($rad?->r)->toBeCloseTo(0.0)
            ->and($rad?->g)->toBeCloseTo(1.0)
            ->and($rad?->b)->toBeCloseTo(1.0);
    });

    it('snaps opaque xyz-d50 colors near short hex values but preserves translucent ones', function () {
        $snapped = $this->converter->tryConvertToRgba(new FunctionNode('color', [
            new StringNode('xyz-d50'),
            new NumberNode(0.116),
            new NumberNode(0.073),
            new NumberNode(0.233),
        ]));

        $unsnapped = $this->converter->tryConvertToRgba(new FunctionNode('color', [
            new StringNode('xyz-d50'),
            new NumberNode(0.12),
            new NumberNode(0.07),
            new NumberNode(0.23),
        ]));

        $translucent = $this->converter->tryConvertToRgba(new FunctionNode('color', [new ListNode([
            new StringNode('xyz-d50'),
            new NumberNode(0.116),
            new NumberNode(0.073),
            new NumberNode(0.233),
            new StringNode('/'),
            new NumberNode(0.5),
        ], 'space')]));

        expect($snapped)->toBeInstanceOf(RgbColor::class)
            ->and($snapped?->r)->toBe(0.4)
            ->and($snapped?->g)->toBe(0.2)
            ->and($snapped?->b)->toBe(0.6)
            ->and($snapped?->a)->toBe(1.0)
            ->and($unsnapped)->toBeInstanceOf(RgbColor::class)
            ->and($unsnapped?->a)->toBe(1.0)
            ->and($unsnapped?->r)->not->toBe(0.4)
            ->and($translucent)->toBeInstanceOf(RgbColor::class)
            ->and($translucent?->a)->toBe(0.5)
            ->and($translucent?->r)->not->toBe(0.4);
    });
});
