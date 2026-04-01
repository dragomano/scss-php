<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Tests\ReflectionAccessor;

describe('CssColorFunctionConverter', function () {
    beforeEach(function () {
        $this->converter = new CssColorFunctionConverter();
        $this->accessor = new ReflectionAccessor($this->converter);
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

    it('snaps xyz-d50 colors near short hex rgb values', function () {
        $rgba = $this->converter->tryConvertToRgba(new FunctionNode('color', [
            new StringNode('xyz-d50'),
            new NumberNode(0.21586050011389923),
            new NumberNode(0.21806601717798211),
            new NumberNode(0.20068229634493385),
        ]));

        $snapped = $this->accessor->callMethod('snapNearShortHexRgbChannels', [
            new RgbColor(102.1 / 255.0, 101.9 / 255.0, 84.9 / 255.0, 1.0),
        ]);

        expect($rgba)->not->toBeNull()
            ->and($snapped->rValue())->toBeCloseTo(102 / 255, 0.0000001)
            ->and($snapped->gValue())->toBeCloseTo(102 / 255, 0.0000001)
            ->and($snapped->bValue())->toBeCloseTo(85 / 255, 0.0000001);
    });

    it('handles private helper edge cases and conversions', function () {
        expect($this->accessor->callMethod('parseCssHslFunction', [
            new FunctionNode('hsl', [new StringNode('oops')]),
        ]))->toBeNull()
            ->and($this->accessor->callMethod('parseCssLabLike', [
                new FunctionNode('lab', [new StringNode('oops')]),
                static fn(float $l, float $a, float $b, float $opacity) => null,
                125.0,
            ]))->toBeNull()
            ->and($this->accessor->callMethod('parseOklabLikeToXyzD65', [
                new FunctionNode('oklab', [new StringNode('oops')]),
            ]))->toBeNull()
            ->and($this->accessor->callMethod('extractThreeChannels', [
                new FunctionNode('rgb', [new ListNode([new NumberNode(1), new NumberNode(2)], 'space')]),
            ]))->toBeNull()
            ->and($this->accessor->callMethod('withParsedThreeChannels', [
                new FunctionNode('rgb', [new StringNode('oops')]),
                static fn($node) => null,
                static fn($node) => null,
                static fn($node) => null,
                static fn(float $a, float $b, float $c, float $d) => null,
            ]))->toBeNull()
            ->and($this->accessor->callMethod('parseThreeChannelsWithAlpha', [
                [new StringNode('oops'), new NumberNode(1), new NumberNode(2)],
                null,
                static fn($node) => null,
                static fn($node) => 1.0,
                static fn($node) => 2.0,
            ]))->toBeNull()
            ->and($this->accessor->callMethod('parseChannelValue', [
                new StringNode('oops'),
                static fn(float $value, string $unit) => $value,
            ]))->toBeNull()
            ->and($this->accessor->callMethod('applyPercentFraction', [10.0, 'px']))->toBeNull()
            ->and($this->accessor->callMethod('parseAbsoluteChannel', [new NumberNode(1, 'px'), 150.0]))->toBeNull()
            ->and($this->accessor->callMethod('parseRgbChannelValue', [new NumberNode(50, '%')]))->toBe(0.5)
            ->and($this->accessor->callMethod('parseRgbChannelValue', [new NumberNode(1, 'px')]))->toBeNull()
            ->and($this->accessor->callMethod('parseColorFunctionChannel', [new NumberNode(25, '%')]))->toBe(0.25)
            ->and($this->accessor->callMethod('parseColorFunctionChannel', [new NumberNode(1, 'px')]))->toBeNull()
            ->and($this->accessor->callMethod('parseHueDegrees', [new NumberNode(M_PI, 'rad')]))->toBeCloseTo(180.0, 0.000001)
            ->and($this->accessor->callMethod('parseHueDegrees', [new NumberNode(200, 'grad')]))->toBeCloseTo(180.0, 0.000001)
            ->and($this->accessor->callMethod('parseHueDegrees', [new NumberNode(0.5, 'turn')]))->toBeCloseTo(180.0, 0.000001)
            ->and($this->accessor->callMethod('parseHueDegrees', [new NumberNode(1, 'px')]))->toBeNull()
            ->and($this->accessor->callMethod('parseLabLightness', [new NumberNode(1, 'px')]))->toBeNull();
    });

    it('returns rgba unchanged when snap helper should not shorten channels', function () {
        $alphaPreserved = $this->accessor->callMethod('snapNearShortHexRgbChannels', [
            new RgbColor(0.4, 0.4, 0.333, 0.5),
        ]);
        $notNearShortHex = $this->accessor->callMethod('snapNearShortHexRgbChannels', [
            new RgbColor(0.41, 0.42, 0.43, 1.0),
        ]);

        expect($alphaPreserved->a)->toBe(0.5)
            ->and($alphaPreserved->rValue())->toBeCloseTo(0.4, 0.000001)
            ->and($notNearShortHex->rValue())->toBeCloseTo(0.41, 0.000001)
            ->and($notNearShortHex->gValue())->toBeCloseTo(0.42, 0.000001)
            ->and($notNearShortHex->bValue())->toBeCloseTo(0.43, 0.000001);
    });
});
