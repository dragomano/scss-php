<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\ColorModuleFactory;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorSpaceConverter', function () {
    beforeEach(function () {
        $context = new ColorModuleContext(
            errorCtx: static fn(string $name): string => $name,
            isGlobalBuiltinCall: static fn(): bool => false,
            warn: static function (): void {},
        );
        $services = (new ColorModuleFactory())->create($context);

        $this->interop = $services->spaceConverter;
    });

    it('detects semantic missing lightness channels', function () {
        $color = new FunctionNode('lch', [new ListNode([
            new StringNode('none'),
            new NumberNode(10.0),
            new NumberNode(20.0, 'deg'),
        ], 'space')]);

        expect($this->interop->isSemanticChannelMissing($color))->toBeTrue();
    });

    it('reads hsl with missing hue channels', function () {
        $hsl = $this->interop->toHslWithMissingChannels(new FunctionNode('hsl', [new ListNode([
            new StringNode('none'),
            new NumberNode(50.0, '%'),
            new NumberNode(25.0, '%'),
        ], 'space')]));

        expect($hsl)->not->toBeNull()
            ->and($hsl?->h)->toBeNull()
            ->and($hsl?->s)->toBe(50.0)
            ->and($hsl?->l)->toBe(25.0);
    });

    it('preserves missing lightness and hue when converting native oklch to lch', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new NumberNode(0.0),
                new StringNode('none'),
            ], 'space')]),
            new StringNode('lch'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('lch')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[0]->value)->toBe('none')
            ->and($result->arguments[0]->items[2])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[2]->value)->toBe('none');
    });

    it('preserves semantic missing lightness when converting hsl to lch', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('hsl', [new ListNode([
                new NumberNode(120.0, 'deg'),
                new NumberNode(50.0, '%'),
                new StringNode('none'),
            ], 'space')]),
            new StringNode('lch'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('lch')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[0]->value)->toBe('none');
    });

    it('preserves all missing generic channels when converting to perceptual spaces', function () {
        foreach (['lab', 'lch', 'oklab', 'oklch'] as $space) {
            $result = $this->interop->toSpace([
                new FunctionNode('color', [new ListNode([
                    new StringNode('a98-rgb'),
                    new StringNode('none'),
                    new StringNode('none'),
                    new StringNode('none'),
                ], 'space')]),
                new StringNode($space),
            ]);

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe($space)
                ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
                ->and($result->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
                ->and($result->arguments[0]->items[0]->value)->toBe('none')
                ->and($result->arguments[0]->items[1])->toBeInstanceOf(StringNode::class)
                ->and($result->arguments[0]->items[1]->value)->toBe('none')
                ->and($result->arguments[0]->items[2])->toBeInstanceOf(StringNode::class)
                ->and($result->arguments[0]->items[2]->value)->toBe('none');
        }
    });

    it('preserves all missing functional channels when converting to a generic space', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('lab', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new StringNode('prophoto-rgb'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[1])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[1]->value)->toBe('none')
            ->and($result->arguments[0]->items[2])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[2]->value)->toBe('none')
            ->and($result->arguments[0]->items[3])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[3]->value)->toBe('none');
    });

    it('gamma-encodes ProPhoto RGB output channels', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('color', [new ListNode([
                new StringNode('xyz-d50'),
                new NumberNode(0.5),
                new NumberNode(0.5),
                new NumberNode(0.5),
            ], 'space')]),
            new StringNode('prophoto-rgb'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($result->arguments[0]->items[1]->value)->toBeGreaterThan(0.5);
    });

    it('converts native oklch with present channels to numeric lch lightness and hue', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(62.0, '%'),
                new NumberNode(0.12),
                new NumberNode(210.0, 'deg'),
            ], 'space')]),
            new StringNode('lch'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('lch')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[0])->toBeInstanceOf(NumberNode::class)
            ->and($result->arguments[0]->items[2])->toBeInstanceOf(NumberNode::class);
    });

    it('serializes zero-chroma oklch conversions with missing hue', function () {
        $result = $this->interop->toSpace([
            new ColorNode('#808080'),
            new StringNode('oklch'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch')
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($result->arguments[0]->items[2])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[0]->items[2]->value)->toBe('none');
    });

    it('converts ProPhoto RGB through XYZ D50 to XYZ D65', function () {
        $color = new FunctionNode('color', [new ListNode([
            new StringNode('prophoto-rgb'),
            new NumberNode(0.5),
            new NumberNode(0.25),
            new NumberNode(0.75),
        ])]);

        $xyzD50 = $this->interop->toSpace([$color, new StringNode('xyz-d50')]);
        $xyzD65 = $this->interop->toSpace([$color, new StringNode('xyz')]);

        expect($xyzD50)->toBeInstanceOf(FunctionNode::class)
            ->and($xyzD50->name)->toBe('color')
            ->and($xyzD65)->toBeInstanceOf(FunctionNode::class)
            ->and($xyzD65->name)->toBe('color');
    });

    it('converts out-of-range ProPhoto RGB through XYZ D65', function () {
        $color = new FunctionNode('color', [new ListNode([
            new StringNode('prophoto-rgb'),
            new NumberNode(-0.5),
            new NumberNode(1.5),
            new NumberNode(2.0),
        ])]);

        $result = $this->interop->toSpace([$color, new StringNode('xyz')]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color');
    });

    it('uses Dart Sass A98 RGB matrix for far out-of-range values', function () {
        $color = new FunctionNode('color', [new ListNode([
            new StringNode('a98-rgb'),
            new NumberNode(-999999.0),
            new NumberNode(0.0),
            new NumberNode(0.0),
        ])]);

        $result = $this->interop->toSpace([$color, new StringNode('xyz')]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class);

        /** @var FunctionNode $result */
        /** @var ListNode $channels */
        $channels = $result->arguments[0];

        expect($channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[3])->toBeInstanceOf(NumberNode::class);

        /** @var NumberNode $x */
        $x = $channels->items[1];
        /** @var NumberNode $z */
        $z = $channels->items[3];

        expect($x->value)->toBeCloseTo(-9041452038524.758, 6)
            ->and($z->value)->toBeCloseTo(-423818064305.84784, 6);
    });

    it('preserves unclamped legacy RGB values and missing channels in XYZ output', function () {
        $outOfRange = new FunctionNode('rgb', [new ListNode([
            new NumberNode(-50.0),
            new NumberNode(100.0),
            new NumberNode(400.0),
        ], 'space')]);
        $missingRed = new FunctionNode('rgb', [new ListNode([
            new StringNode('none'),
            new NumberNode(20.0),
            new NumberNode(30.0),
        ], 'space')]);

        $xyz        = $this->interop->toSpace([$outOfRange, new StringNode('xyz')]);
        $xyzMissing = $this->interop->toSpace([$missingRed, new StringNode('xyz')]);

        expect($xyz)->toBeInstanceOf(FunctionNode::class)
            ->and($xyzMissing)->toBeInstanceOf(FunctionNode::class);

        /** @var FunctionNode $xyz */
        /** @var ListNode $xyzChannels */
        $xyzChannels = $xyz->arguments[0];
        /** @var FunctionNode $xyzMissing */
        /** @var ListNode $missingChannels */
        $missingChannels = $xyzMissing->arguments[0];

        expect($xyzChannels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($xyzChannels->items[1]->value)->toBeCloseTo(0.5403326817)
            ->and($missingChannels->items[1])->toBeInstanceOf(StringNode::class)
            ->and($missingChannels->items[1]->value)->toBe('none');
    });

    it('converts colors to xyz-d50 and wide-gamut generic spaces', function () {
        $xyzD50          = $this->interop->toSpace([new ColorNode('#036'), new StringNode('xyz-d50')]);
        $displayP3Linear = $this->interop->toSpace([new ColorNode('#036'), new StringNode('display-p3-linear')]);
        $a98             = $this->interop->toSpace([new ColorNode('#036'), new StringNode('a98-rgb')]);
        $prophoto        = $this->interop->toSpace([new ColorNode('#036'), new StringNode('prophoto-rgb')]);
        $rec2020         = $this->interop->toSpace([new ColorNode('#036'), new StringNode('rec2020')]);

        expect($xyzD50)->toBeInstanceOf(FunctionNode::class)
            ->and($xyzD50->name)->toBe('color')
            ->and($xyzD50->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($xyzD50->arguments[0]->items[0]->value)->toBe('xyz-d50')
            ->and($displayP3Linear)->toBeInstanceOf(FunctionNode::class)
            ->and($displayP3Linear->arguments[0]->items[0]->value)->toBe('display-p3-linear')
            ->and($a98)->toBeInstanceOf(FunctionNode::class)
            ->and($a98->arguments[0]->items[0]->value)->toBe('a98-rgb')
            ->and($prophoto)->toBeInstanceOf(FunctionNode::class)
            ->and($prophoto->arguments[0]->items[0]->value)->toBe('prophoto-rgb')
            ->and($rec2020)->toBeInstanceOf(FunctionNode::class)
            ->and($rec2020->arguments[0]->items[0]->value)->toBe('rec2020');
    });

    it('rejects unknown gamut mapping methods and no-ops unbounded target spaces', function () {
        expect(fn() => $this->interop->toGamut([
            new ColorNode('#036'),
            new StringNode('rgb'),
            new StringNode('weird'),
        ], []))->toThrow(UnsupportedColorValueException::class, 'Unknown gamut mapping method "weird"')
            ->and($this->interop->toGamut([
                new ColorNode('#036'),
                new StringNode('oklch'),
            ], []))->toBeInstanceOf(ColorNode::class);
    });

    it('clips out-of-gamut srgb colors in to-gamut', function () {
        $color = new FunctionNode('color', [new ListNode([
            new StringNode('srgb'),
            new NumberNode(1.2),
            new NumberNode(0.1),
            new NumberNode(0.0),
        ], 'space')]);

        $result = $this->interop->toGamut([
            $color,
            new StringNode('rgb'),
            new StringNode('clip'),
        ], []);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result)->not->toBe($color);
    });

    it('maps out-of-gamut srgb colors with local-minde', function () {
        $color = new FunctionNode('color', [new ListNode([
            new StringNode('srgb'),
            new NumberNode(1.2),
            new NumberNode(0.1),
            new NumberNode(0.0),
        ], 'space')]);

        $result = $this->interop->toGamut([
            $color,
            new StringNode('rgb'),
            new StringNode('local-minde'),
        ], []);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result)->not->toBe($color);
    });

    it('converts lch colors to oklch while preserving missing-channel semantics', function () {
        $result = $this->interop->toOklchPreservingMissingChannels(
            new FunctionNode('lch', [new ListNode([
                new StringNode('none'),
                new NumberNode(30.0),
                new NumberNode(450.0, 'deg'),
            ], 'space')]),
        );

        expect($result->lValue())->toBeFloat()
            ->and($result->cValue())->toBeFloat()
            ->and($result->hValue())->toBeFloat();
    });

    it('converts lch colors with explicit lightness to oklch', function () {
        $result = $this->interop->toOklchPreservingMissingChannels(
            new FunctionNode('lch', [new ListNode([
                new NumberNode(40.0, '%'),
                new NumberNode(30.0),
                new NumberNode(450.0, 'deg'),
            ], 'space')]),
        );

        expect($result->lValue())->toBeFloat()
            ->and($result->cValue())->toBeFloat()
            ->and($result->hValue())->toBeFloat();
    });

    it('converts rgb colors to working-space channels and rejects unsupported spaces', function () {
        $rgb = new RgbColor(12.0, 34.0, 56.0, 0.25);

        $a98      = $this->interop->rgbToWorkingSpaceChannels($rgb, 'a98-rgb');
        $prophoto = $this->interop->rgbToWorkingSpaceChannels($rgb, 'prophoto-rgb');
        $rec2020  = $this->interop->rgbToWorkingSpaceChannels($rgb, 'rec2020');

        expect($a98)->toHaveCount(3)
            ->and($prophoto)->toHaveCount(3)
            ->and($rec2020)->toHaveCount(3)
            ->and(fn() => $this->interop->rgbToWorkingSpaceChannels($rgb, 'bogus'))
            ->toThrow(UnsupportedColorSpaceException::class)
            ->and(fn() => $this->interop->workingSpaceChannelsToRgb('bogus', [0.1, 0.2, 0.3], 0.25))
            ->toThrow(UnsupportedColorSpaceException::class);
    });

    it('handles incomplete and alpha-bearing hsl channel extraction', function () {
        $incomplete = $this->interop->toHslWithMissingChannels(new FunctionNode('hsl', [
            new NumberNode(120.0, 'deg'),
            new NumberNode(50.0, '%'),
        ]));

        $withAlpha = $this->interop->toHslWithMissingChannels(new FunctionNode('hsl', [new ListNode([
            new NumberNode(120.0, 'deg'),
            new NumberNode(50.0, '%'),
            new NumberNode(25.0, '%'),
            new NumberNode(0.25),
        ], 'space')]));

        expect($incomplete)->toBeNull()
            ->and($withAlpha)->not->toBeNull()
            ->and($withAlpha?->a)->toBe(0.25);
    });

    it('returns false when semantic missing lightness is unavailable', function () {
        $result = $this->interop->isSemanticChannelMissing(new FunctionNode('rgb', []));

        expect($result)->toBeFalse();
    });

    it('returns 0.0 alpha when hsl alpha channel is marked as none', function () {
        $withMissingAlpha = $this->interop->toHslWithMissingChannels(new FunctionNode('hsl', [new ListNode([
            new NumberNode(120.0, 'deg'),
            new NumberNode(50.0, '%'),
            new NumberNode(25.0, '%'),
            new StringNode('none'),
        ], 'space')]));

        expect($withMissingAlpha)->not->toBeNull()
            ->and($withMissingAlpha?->a)->toBe(0.0);
    });

    it('preserves negative unclamped lab channels when converting to hwb', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('lab', [new ListNode([
                new NumberNode(-50.0, '%'),
                new NumberNode(-150.0),
                new NumberNode(150.0),
            ], 'space')]),
            new StringNode('hwb'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('hsl')
            ->and($result->arguments[2])->toBeInstanceOf(NumberNode::class)
            ->and($result->arguments[2]->value)->toBeLessThan(0.0);
    });

    it('preserves missing lch channel semantics when converting to hwb', function () {
        $missingHue = $this->interop->toSpace([
            new FunctionNode('lch', [new ListNode([
                new NumberNode(10.0, '%'),
                new NumberNode(20.0),
                new StringNode('none'),
            ], 'space')]),
            new StringNode('hwb'),
        ]);
        $missingNonHue = $this->interop->toSpace([
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new NumberNode(10.0, 'deg'),
            ], 'space')]),
            new StringNode('hwb'),
        ]);

        expect($missingHue)->toBeInstanceOf(FunctionNode::class)
            ->and($missingHue->arguments[0])->toBeInstanceOf(NumberNode::class)
            ->and($missingHue->arguments[0]->value)->toBe(0.0)
            ->and($missingNonHue)->toBeInstanceOf(ColorNode::class)
            ->and($missingNonHue->value)->toBe('red');
    });

    it('converts missing oklch channels directly to oklab coordinates', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new NumberNode(0.1),
                new NumberNode(30.0, 'deg'),
            ], 'space')]),
            new StringNode('oklab'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class);

        /** @var ListNode $channels */
        $channels = $result->arguments[0];

        expect($channels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($channels->items[0]->value)->toBe('none')
            ->and($channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[1]->value)->toBeCloseTo(0.0866025404)
            ->and($channels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[2]->value)->toBeCloseTo(0.05);
    });

    it('preserves missing lch lightness when converting to oklab', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('lch', [new ListNode([
                new StringNode('none'),
                new NumberNode(20.0),
                new NumberNode(30.0, 'deg'),
            ], 'space')]),
            new StringNode('oklab'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->arguments[0])->toBeInstanceOf(ListNode::class);

        /** @var ListNode $channels */
        $channels = $result->arguments[0];

        expect($channels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($channels->items[0]->value)->toBe('none')
            ->and($channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[1]->value)->toBeCloseTo(0.4083922377)
            ->and($channels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[2]->value)->toBeCloseTo(0.0807817404);
    });
});
