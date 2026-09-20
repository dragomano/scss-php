<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\ColorModuleFactory;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
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

    it('builds a color-mix node when native oklch lightness is out of range', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(150.0, '%'),
                new NumberNode(0.1),
                new NumberNode(30.0, 'deg'),
            ], 'space')]),
            new StringNode('oklch'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color-mix');

        /** @var ListNode $space */
        $space = $result->arguments[0];
        /** @var ListNode $mix */
        $mix = $result->arguments[1];

        expect($space->items[1])->toBeInstanceOf(StringNode::class)
            ->and($space->items[1]->value)->toBe('oklch')
            ->and($mix->items[0])->toBeInstanceOf(FunctionNode::class)
            ->and($mix->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($mix->items[1]->value)->toBe(100)
            ->and($result->arguments[2])->toBeInstanceOf(StringNode::class)
            ->and($result->arguments[2]->value)->toBe('black');
    });

    it('builds a color-mix node for out-of-range lch lightness in to-gamut', function () {
        $result = $this->interop->toGamut([
            new FunctionNode('lch', [new ListNode([
                new NumberNode(150.0, '%'),
                new NumberNode(30.0),
                new NumberNode(40.0, 'deg'),
            ], 'space')]),
            new StringNode('lch'),
        ], []);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color-mix');

        /** @var ListNode $mix */
        $mix = $result->arguments[1];
        /** @var FunctionNode $inner */
        $inner = $mix->items[0];
        /** @var ListNode $channels */
        $channels = $inner->arguments[0];

        expect($channels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($channels->items[0]->value)->toBe('xyz')
            ->and($channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[1]->value)->toBeCloseTo(3.025098972946619, 6);
    });

    it('builds a color-mix node when converting out-of-range lch lightness to lab', function () {
        $result = $this->interop->toSpace([
            new FunctionNode('lch', [new ListNode([
                new NumberNode(150.0, '%'),
                new NumberNode(30.0),
                new NumberNode(40.0, 'deg'),
            ], 'space')]),
            new StringNode('lab'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color-mix');

        /** @var ListNode $space */
        $space = $result->arguments[0];

        expect($space->items[1])->toBeInstanceOf(StringNode::class)
            ->and($space->items[1]->value)->toBe('lab');
    });

    it('normalizes hue angles expressed in turns, radians, and gradians', function () {
        $halfTurn = $this->interop->toSpace([
            new FunctionNode('hsl', [new ListNode([
                new NumberNode(0.5, 'turn'),
                new NumberNode(50.0, '%'),
                new NumberNode(50.0, '%'),
            ], 'space')]),
            new StringNode('lch'),
        ]);
        $radian = $this->interop->toSpace([
            new FunctionNode('hsl', [new ListNode([
                new NumberNode(1.0, 'rad'),
                new NumberNode(50.0, '%'),
                new NumberNode(50.0, '%'),
            ], 'space')]),
            new StringNode('lch'),
        ]);
        $gradian = $this->interop->toSpace([
            new FunctionNode('hsl', [new ListNode([
                new NumberNode(200.0, 'grad'),
                new NumberNode(50.0, '%'),
                new NumberNode(50.0, '%'),
            ], 'space')]),
            new StringNode('lch'),
        ]);

        /** @var ListNode $halfChannels */
        $halfChannels = $halfTurn->arguments[0];
        /** @var ListNode $radChannels */
        $radChannels = $radian->arguments[0];
        /** @var ListNode $gradChannels */
        $gradChannels = $gradian->arguments[0];

        expect($halfTurn)->toBeInstanceOf(FunctionNode::class)
            ->and($halfTurn->name)->toBe('lch')
            ->and($halfChannels->items[0])->toBeInstanceOf(NumberNode::class)
            ->and($halfChannels->items[0]->value)->toBeCloseTo(70.70400391007473)
            ->and($halfChannels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($halfChannels->items[2]->unit)->toBe('deg')
            ->and($halfChannels->items[2]->value)->toBeCloseTo(196.83909590479425)
            ->and($halfChannels->items[0]->value)->toBeCloseTo($gradChannels->items[0]->value)
            ->and($halfChannels->items[2]->value)->toBeCloseTo($gradChannels->items[2]->value)
            ->and($radChannels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($radChannels->items[2]->value)->toBeCloseTo(98.2199188313553);
    });

    it('converts semantically missing hsl lightness to zeroed lab and oklab channels', function () {
        $lab = $this->interop->toSpace([
            new FunctionNode('hsl', [new ListNode([
                new NumberNode(120.0, 'deg'),
                new NumberNode(50.0, '%'),
                new StringNode('none'),
            ], 'space')]),
            new StringNode('lab'),
        ]);
        $oklab = $this->interop->toSpace([
            new FunctionNode('hsl', [new ListNode([
                new NumberNode(120.0, 'deg'),
                new NumberNode(50.0, '%'),
                new StringNode('none'),
            ], 'space')]),
            new StringNode('oklab'),
        ]);

        /** @var ListNode $labChannels */
        $labChannels = $lab->arguments[0];
        /** @var ListNode $oklabChannels */
        $oklabChannels = $oklab->arguments[0];

        expect($lab)->toBeInstanceOf(FunctionNode::class)
            ->and($lab->name)->toBe('lab')
            ->and($labChannels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($labChannels->items[0]->value)->toBe('none')
            ->and($labChannels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($labChannels->items[1]->value)->toBe(0.0)
            ->and($labChannels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($labChannels->items[2]->value)->toBe(0.0)
            ->and($oklab)->toBeInstanceOf(FunctionNode::class)
            ->and($oklab->name)->toBe('oklab')
            ->and($oklabChannels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($oklabChannels->items[0]->value)->toBe('none')
            ->and($oklabChannels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($oklabChannels->items[1]->value)->toBe(0.0)
            ->and($oklabChannels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($oklabChannels->items[2]->value)->toBe(0.0);
    });

    it('serializes ColorNode conversions through hwb', function () {
        $hsl = $this->interop->toSpace([
            new ColorNode('#036'),
            new StringNode('hwb'),
        ]);
        $black = $this->interop->toSpace([
            new ColorNode('black'),
            new StringNode('hwb'),
        ]);
        $white = $this->interop->toSpace([
            new ColorNode('white'),
            new StringNode('hwb'),
        ]);

        expect($hsl)->toBeInstanceOf(FunctionNode::class)
            ->and($hsl->name)->toBe('hsl')
            ->and($hsl->arguments[0])->toBeInstanceOf(NumberNode::class)
            ->and($hsl->arguments[0]->value)->toBe(210.0)
            ->and($hsl->arguments[1])->toBeInstanceOf(NumberNode::class)
            ->and($hsl->arguments[1]->value)->toBe(100.0)
            ->and($hsl->arguments[2])->toBeInstanceOf(NumberNode::class)
            ->and($hsl->arguments[2]->value)->toBe(20.0)
            ->and($black)->toBeInstanceOf(ColorNode::class)
            ->and($black->value)->toBe('black')
            ->and($white)->toBeInstanceOf(ColorNode::class)
            ->and($white->value)->toBe('white');
    });

    it('converts ColorNodes to generic rgb-family spaces', function () {
        $srgb = $this->interop->toSpace([
            new ColorNode('#036'),
            new StringNode('srgb'),
        ]);
        $linear = $this->interop->toSpace([
            new ColorNode('#036'),
            new StringNode('srgb-linear'),
        ]);
        $p3 = $this->interop->toSpace([
            new ColorNode('#036'),
            new StringNode('display-p3'),
        ]);

        /** @var ListNode $srgbChannels */
        $srgbChannels = $srgb->arguments[0];
        /** @var ListNode $linearChannels */
        $linearChannels = $linear->arguments[0];
        /** @var ListNode $p3Channels */
        $p3Channels = $p3->arguments[0];

        expect($srgb)->toBeInstanceOf(FunctionNode::class)
            ->and($srgb->name)->toBe('color')
            ->and($srgbChannels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($srgbChannels->items[0]->value)->toBe('srgb')
            ->and($srgbChannels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($srgbChannels->items[1]->value)->toBeCloseTo(0.0)
            ->and($srgbChannels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($srgbChannels->items[2]->value)->toBeCloseTo(0.2)
            ->and($srgbChannels->items[3])->toBeInstanceOf(NumberNode::class)
            ->and($srgbChannels->items[3]->value)->toBeCloseTo(0.4)
            ->and($linearChannels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($linearChannels->items[0]->value)->toBe('srgb-linear')
            ->and($linearChannels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($linearChannels->items[2]->value)->toBeCloseTo(0.033104766570885055)
            ->and($p3Channels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($p3Channels->items[0]->value)->toBe('display-p3')
            ->and($p3Channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($p3Channels->items[1]->value)->toBeCloseTo(0.06909232748693148);
    });

    it('rejects unsupported generic color spaces', function () {
        $unknown = new FunctionNode('color', [new ListNode([
            new StringNode('foo'),
            new NumberNode(1.0),
            new NumberNode(2.0),
            new NumberNode(3.0),
        ], 'space')]);

        expect(fn() => $this->interop->toSpace([$unknown, new StringNode('srgb')]))
            ->toThrow(UnsupportedColorValueException::class, "Unsupported color value 'foo'.")
            ->and(fn() => $this->interop->toSpace([$unknown, new StringNode('lch')]))
            ->toThrow(UnsupportedColorValueException::class, "Unsupported color value 'foo'.");
    });

    it('unwraps out-of-gamut color-mix nodes when targeting unbounded spaces', function () {
        $mix = new FunctionNode('color-mix', [
            new ListNode([
                new StringNode('in'),
                new StringNode('srgb'),
            ], 'space'),
            new ListNode([
                new FunctionNode('color', [new ListNode([
                    new StringNode('srgb'),
                    new NumberNode(1.0),
                    new NumberNode(0.0),
                    new NumberNode(0.0),
                ], 'space')]),
                new NumberNode(100.0, '%'),
            ], 'space'),
            new StringNode('50%'),
        ]);

        $result = $this->interop->toGamut([
            $mix,
            new StringNode('xyz'),
        ], []);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result)->not->toBe($mix)
            ->and($result->name)->toBe('color');
    });

    it('extracts lch missing data from color nodes', function () {
        $data = $this->interop->extractLchMissingData(new ColorNode('#036'));

        expect($data->l)->toBeCloseTo(20.745745307318415)
            ->and($data->c)->toBeCloseTo(35.038973335513056)
            ->and($data->h)->toBeCloseTo(273.0881809283295)
            ->and($data->a)->toBeCloseTo(1.0)
            ->and($data->lightnessMissing)->toBeFalse()
            ->and($data->chromaMissing)->toBeFalse()
            ->and($data->hueMissing)->toBeFalse();
    });

    it('applies local-minde gamut mapping from wide-gamut sources', function () {
        $a98 = $this->interop->toGamut([
            new FunctionNode('color', [new ListNode([
                new StringNode('a98-rgb'),
                new NumberNode(0.9),
                new NumberNode(-0.4),
                new NumberNode(0.2),
            ], 'space')]),
            new StringNode('srgb'),
            new StringNode('local-minde'),
        ], []);
        $p3 = $this->interop->toGamut([
            new FunctionNode('color', [new ListNode([
                new StringNode('display-p3'),
                new NumberNode(1.4),
                new NumberNode(-0.4),
                new NumberNode(0.3),
            ], 'space')]),
            new StringNode('srgb'),
            new StringNode('local-minde'),
        ], []);

        /** @var ListNode $a98Channels */
        $a98Channels = $a98->arguments[0];
        /** @var ListNode $p3Channels */
        $p3Channels = $p3->arguments[0];

        expect($a98)->toBeInstanceOf(FunctionNode::class)
            ->and($a98->name)->toBe('color')
            ->and($a98Channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($a98Channels->items[1]->value)->toBeCloseTo(0.7877692658)
            ->and($a98Channels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($a98Channels->items[2]->value)->toBeCloseTo(0.0)
            ->and($p3)->toBeInstanceOf(FunctionNode::class)
            ->and($p3->name)->toBe('color')
            ->and($p3Channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($p3Channels->items[1]->value)->toBeCloseTo(0.9439230801);
    });

    it('returns the converged bisection result when local-minde fights over dark colors', function () {
        $result = $this->interop->toGamut([
            new FunctionNode('color', [new ListNode([
                new StringNode('display-p3'),
                new NumberNode(0.9),
                new NumberNode(-0.2),
                new NumberNode(-0.1),
            ], 'space')]),
            new StringNode('hsl'),
            new StringNode('local-minde'),
        ], []);

        /** @var ListNode $channels */
        $channels = $result->arguments[0];

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color')
            ->and($channels->items[0])->toBeInstanceOf(StringNode::class)
            ->and($channels->items[0]->value)->toBe('display-p3')
            ->and($channels->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[1]->value)->toBeCloseTo(0.5137786888)
            ->and($channels->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[2]->value)->toBeCloseTo(0.8560520778)
            ->and($channels->items[3])->toBeInstanceOf(NumberNode::class)
            ->and($channels->items[3]->value)->toBeCloseTo(0.2930808717);
    });
});
