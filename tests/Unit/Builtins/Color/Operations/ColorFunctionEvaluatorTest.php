<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\ColorModuleFactory;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownColorChannelException;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

describe('ColorFunctionEvaluator', function () {
    beforeEach(function () {
        $context = new ColorModuleContext(
            errorCtx: static fn(string $function): string => $function,
            isGlobalBuiltinCall: static fn(): bool => false,
            warn: static function (?BuiltinCallContext $callContext, string $message): void {
                $callContext?->warn($message);
            },
        );

        $this->evaluator = (new ColorModuleFactory())->create($context)->functions;
    });

    it('falls back to adjust-color for non-legacy hue and alpha adjustments', function () {
        $oklch     = new FunctionNode('oklch', [new NumberNode(50, '%'), new NumberNode(0.12), new NumberNode(70, 'deg')]);
        $withAlpha = new FunctionNode('oklch', [new ListNode([
            new NumberNode(50, '%'),
            new NumberNode(0.12),
            new NumberNode(70, 'deg'),
            new StringNode('/'),
            new NumberNode(0.5),
        ], 'space')]);

        $adjustedHue   = $this->evaluator->adjustHue([$oklch, new NumberNode(45, 'deg')], null);
        $adjustedAlpha = $this->evaluator->adjustAlphaChannel([$withAlpha, new NumberNode(0.2)], 1, 'fade-in', null);

        expect($adjustedHue)->toBeInstanceOf(FunctionNode::class)
            ->and($adjustedHue->name)->toBe('oklch')
            ->and($adjustedAlpha)->toBeInstanceOf(FunctionNode::class)
            ->and($adjustedAlpha->name)->toBe('oklch');
    });

    it('applies whiteness adjustments to legacy colors through an hwb round trip', function () {
        $result = $this->evaluator->adjustColorChannelByPercent(
            [new ColorNode('#112233'), new NumberNode(10, '%')],
            'whiteness',
            1,
            'whiteness',
        );

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb');
    });

    it('reuses origin srgb channels when adjusting whiteness of hsl colors converted from hwb', function () {
        $hslFromHwb = new FunctionNode(
            'hsl',
            [new ListNode([
                new NumberNode(200),
                new NumberNode(50, '%'),
                new NumberNode(40, '%'),
            ], 'space')],
            originColorSpace: 'hwb',
            originSrgbChannels: [0.2, 0.4, 0.6],
        );

        $result = $this->evaluator->adjustColorChannelByPercent(
            [$hslFromHwb, new NumberNode(10, '%')],
            'whiteness',
            1,
            'whiteness',
        );

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('hsl')
            ->and($result->arguments)->toHaveCount(3);
    });

    it('grayscales non-srgb colors while preserving their color space', function () {
        $displayP3 = new FunctionNode('color', [new ListNode([
            new StringNode('display-p3'),
            new NumberNode(0.4),
            new NumberNode(0.2),
            new NumberNode(0.6),
        ], 'space')]);

        $result = $this->evaluator->grayscale([$displayP3]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color');

        /** @var FunctionNode $result */
        $channels = $result->arguments[0];

        expect($channels)->toBeInstanceOf(ListNode::class)
            ->and($channels->items[0]->value)->toBe('display-p3')
            ->and($channels->items[1]->value)->toBeCloseTo(0.3331712936, 0.0000001)
            ->and($channels->items[2]->value)->toBeCloseTo(0.3331712936, 0.0000001)
            ->and($channels->items[3]->value)->toBeCloseTo(0.3331712936, 0.0000001);
    });

    it('mixes hsl colors when the right hue is missing', function () {
        $result = $this->evaluator->mix([
            new FunctionNode('hsl', [
                new NumberNode(120, 'deg'),
                new NumberNode(40, '%'),
                new NumberNode(50, '%'),
            ]),
            new FunctionNode('hsl', [new ListNode([
                new StringNode('none'),
                new NumberNode(60, '%'),
                new NumberNode(40, '%'),
            ], 'space')]),
        ], [
            'method' => new StringNode('hsl'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('hsl');
    });

    it('falls back from hsl-space mixing when colors cannot expose hsl channels with missing values', function () {
        $result = $this->evaluator->mix([
            new FunctionNode('color', [new ListNode([
                new StringNode('display-p3'),
                new NumberNode(0.4),
                new NumberNode(0.2),
                new NumberNode(0.6),
            ], 'space')]),
            new ColorNode('#000'),
        ], [
            'method' => new StringNode('hsl'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('color');
    });

    it('changes native lab colors through the public changeColor api', function () {
        $lab = new FunctionNode('lab', [new NumberNode(40, '%'), new NumberNode(30), new NumberNode(40)]);

        $changed = $this->evaluator->changeColor([$lab], ['lightness' => new NumberNode(10, '%')]);

        expect($changed)->toBeInstanceOf(FunctionNode::class)
            ->and($changed->name)->toBe('lab');
    });

    it('applies lab modifications to legacy and non-legacy colors through public changeColor calls', function () {
        $legacyRgb = $this->evaluator->changeColor([new ColorNode('#336699')], [
            'space' => new StringNode('lab'),
            'lightness' => new NumberNode(10, '%'),
        ]);

        $floatRgb = $this->evaluator->changeColor([
            new FunctionNode('color', [new ListNode([
                new StringNode('display-p3'),
                new NumberNode(0.4),
                new NumberNode(0.2),
                new NumberNode(0.6),
            ], 'space')]),
        ], [
            'space' => new StringNode('lab'),
            'lightness' => new NumberNode(10, '%'),
        ]);

        expect($floatRgb)->toBeInstanceOf(FunctionNode::class)
            ->and($floatRgb->name)->toBe('color')
            ->and($legacyRgb)->toBeInstanceOf(FunctionNode::class);
    });

    it('adjusts lab modifications for legacy colors through public adjustColor calls', function () {
        $result = $this->evaluator->adjustColor([
            new ColorNode('#336699'),
        ], [
            'space' => new StringNode('lab'),
            'lightness' => new NumberNode(10, '%'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb');
    });

    it('emits zero scale suggestions when no alpha range remains', function () {
        $warnings = [];
        $context  = new BuiltinCallContext(
            logWarning: static function (string $message) use (&$warnings): void {
                $warnings[] = $message;
            },
        );

        $this->evaluator->adjustAlphaChannel(
            [new ColorNode('#112233'), new NumberNode(0.2)],
            1,
            'fade-in',
            $context,
        );

        expect($warnings)->toHaveCount(1)
            ->and($warnings[0])->toContain('color.scale(')
            ->and($warnings[0])->toContain('$alpha: 0%');
    });

    it('falls back to rgb mixing for blank mix methods', function () {
        $result = $this->evaluator->mix([
            new ColorNode('#000'),
            new ColorNode('#fff'),
            new NumberNode(50, '%'),
            new StringNode('   '),
        ], []);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('rgb');
    });

    it('handles missing oklch channels and hues through public mix calls', function () {
        $result = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0.2),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        expect($result)->toBeInstanceOf(FunctionNode::class)
            ->and($result->name)->toBe('oklch');
    });

    it('preserves missing oklch channels and reuses present channels during mixing', function () {
        $bothMissing = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        $rightMissingChannel = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0.2),
                new NumberNode(80, 'deg'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(20, '%'),
                new StringNode('none'),
                new NumberNode(10, 'deg'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        $rightMissingHue = $this->evaluator->mix([
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0.2),
                new NumberNode(80, 'deg'),
            ], 'space')]),
            new FunctionNode('oklch', [new ListNode([
                new NumberNode(20, '%'),
                new NumberNode(0.1),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('oklch'),
        ]);

        expect($bothMissing)->toBeInstanceOf(FunctionNode::class)
            ->and($bothMissing->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($bothMissing->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
            ->and($bothMissing->arguments[0]->items[0]->value)->toBe('none')
            ->and($bothMissing->arguments[0]->items[1])->toBeInstanceOf(StringNode::class)
            ->and($bothMissing->arguments[0]->items[1]->value)->toBe('none')
            ->and($bothMissing->arguments[0]->items[2])->toBeInstanceOf(StringNode::class)
            ->and($bothMissing->arguments[0]->items[2]->value)->toBe('none')
            ->and($rightMissingChannel)->toBeInstanceOf(FunctionNode::class)
            ->and($rightMissingChannel->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($rightMissingChannel->arguments[0]->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($rightMissingChannel->arguments[0]->items[1]->value)->toBe(0.2)
            ->and($rightMissingHue)->toBeInstanceOf(FunctionNode::class)
            ->and($rightMissingHue->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($rightMissingHue->arguments[0]->items[2])->toBeInstanceOf(NumberNode::class)
            ->and($rightMissingHue->arguments[0]->items[2]->value)->toBe(80.0);
    });

    it('mixes rec2020 channels with missing values through the public mix api', function () {
        $mixed = $this->evaluator->mix([
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new NumberNode(0.8),
                new NumberNode(0.2),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('rec2020'),
        ]);

        expect($mixed)->toBeInstanceOf(FunctionNode::class)
            ->and($mixed->name)->toBe('color')
            ->and($mixed->arguments)->toHaveCount(1);
    });

    it('mixes rec2020 channels from rgb fallbacks and percent channel inputs', function () {
        $fallback = $this->evaluator->mix([
            new ColorNode('#036'),
            new ColorNode('#369'),
        ], [
            'method' => new StringNode('rec2020'),
        ]);

        $percentChannel = $this->evaluator->mix([
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new NumberNode(25, '%'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
            new FunctionNode('color', [new ListNode([
                new StringNode('rec2020'),
                new StringNode('none'),
                new StringNode('none'),
                new StringNode('none'),
            ], 'space')]),
        ], [
            'method' => new StringNode('rec2020'),
        ]);

        expect($fallback)->toBeInstanceOf(FunctionNode::class)
            ->and($fallback->name)->toBe('rgb')
            ->and($percentChannel)->toBeInstanceOf(FunctionNode::class)
            ->and($percentChannel->arguments[0])->toBeInstanceOf(ListNode::class)
            ->and($percentChannel->arguments[0]->items[1])->toBeInstanceOf(NumberNode::class)
            ->and($percentChannel->arguments[0]->items[1]->value)->toBe(0.25);
    });

    describe('invert through named spaces', function () {
        it('inverts legacy colors through lch and oklab spaces', function () {
            $lch   = $this->evaluator->invert([new ColorNode('#ff0000')], ['space' => new StringNode('lch')]);
            $oklab = $this->evaluator->invert([new ColorNode('#ff0000')], ['space' => new StringNode('oklab')]);

            expect($lch)->toBeInstanceOf(FunctionNode::class)
                ->and($lch->name)->toBe('rgb')
                ->and($lch->arguments[0]->value)->toBeCloseTo(-63.9961199682, 0.0000001)
                ->and($lch->arguments[1]->value)->toBeCloseTo(54.6311154770, 0.0000001)
                ->and($lch->arguments[2]->value)->toBeCloseTo(88.7625860788, 0.0000001)
                ->and($oklab)->toBeInstanceOf(FunctionNode::class)
                ->and($oklab->name)->toBe('rgb')
                ->and($oklab->arguments[0]->value)->toBeCloseTo(-36.5857792343, 0.0000001);
        });

        it('inverts colors with missing channels through lch and oklab spaces', function () {
            $nullLightness = new FunctionNode('oklch', [new ListNode([
                new StringNode('none'),
                new NumberNode(0.2),
                new NumberNode(80, 'deg'),
            ], 'space')]);

            $viaLch   = $this->evaluator->invert([$nullLightness], ['space' => new StringNode('lch')]);
            $viaOklab = $this->evaluator->invert([$nullLightness], ['space' => new StringNode('oklab')]);

            $zeroChroma = $this->evaluator->invert([new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0),
                new NumberNode(80, 'deg'),
            ], 'space')])], ['space' => new StringNode('lch')]);

            expect($viaLch)->toBeInstanceOf(FunctionNode::class)
                ->and($viaLch->name)->toBe('color-mix')
                ->and($viaOklab)->toBeInstanceOf(FunctionNode::class)
                ->and($viaOklab->name)->toBe('oklch')
                ->and($viaOklab->arguments[0]->items[0]->value)->toBeCloseTo(100.0, 0.0000001)
                ->and($zeroChroma)->toBeInstanceOf(FunctionNode::class)
                ->and($zeroChroma->name)->toBe('oklch')
                ->and($zeroChroma->arguments[0]->items[0]->value)->toBeCloseTo(63.7931034483, 0.0000001);
        });

        it('inverts oklab and oklch colors in their native spaces', function () {
            $oklab = $this->evaluator->invert(
                [new FunctionNode('oklab', [new ListNode([
                    new NumberNode(50, '%'),
                    new NumberNode(10),
                    new NumberNode(10),
                ], 'space')])],
                ['space' => new StringNode('oklab')],
            );

            $oklabMissing = $this->evaluator->invert(
                [new FunctionNode('oklab', [new ListNode([
                    new StringNode('none'),
                    new NumberNode(0.1),
                    new NumberNode(0.1),
                ], 'space')])],
                ['space' => new StringNode('oklab')],
            );

            $oklch = $this->evaluator->invert(
                [new FunctionNode('oklch', [new ListNode([
                    new NumberNode(50, '%'),
                    new NumberNode(0.1),
                    new NumberNode(30, 'deg'),
                ], 'space')])],
                ['space' => new StringNode('oklch')],
            );

            $oklchMissing = $this->evaluator->invert(
                [new FunctionNode('oklch', [new ListNode([
                    new StringNode('none'),
                    new NumberNode(0.2),
                    new NumberNode(80, 'deg'),
                ], 'space')])],
                ['space' => new StringNode('oklch')],
            );

            expect($oklab)->toBeInstanceOf(FunctionNode::class)
                ->and($oklab->name)->toBe('oklab')
                ->and($oklab->arguments[0]->items[1]->value)->toBeCloseTo(-10.0, 0.0000001)
                ->and($oklabMissing)->toBeInstanceOf(FunctionNode::class)
                ->and($oklabMissing->name)->toBe('oklab')
                ->and($oklabMissing->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
                ->and($oklabMissing->arguments[0]->items[0]->value)->toBe('none')
                ->and($oklch)->toBeInstanceOf(FunctionNode::class)
                ->and($oklch->name)->toBe('oklch')
                ->and($oklch->arguments[0]->items[2]->value)->toBeCloseTo(210.0, 0.0000001)
                ->and($oklchMissing)->toBeInstanceOf(FunctionNode::class)
                ->and($oklchMissing->name)->toBe('oklch')
                ->and($oklchMissing->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
                ->and($oklchMissing->arguments[0]->items[0]->value)->toBe('none');
        });

        it('keeps missing hue when inverting hsl colors in hsl space', function () {
            $result = $this->evaluator->invert(
                [new FunctionNode('hsl', [new ListNode([
                    new StringNode('none'),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')])],
                ['space' => new StringNode('hsl')],
            );

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('hsl')
                ->and($result->arguments[0]->items[0])->toBeInstanceOf(StringNode::class)
                ->and($result->arguments[0]->items[0]->value)->toBe('none');
        });

        it('rejects invert spaces that cannot be converted from rgb', function () {
            expect(fn(): AstNode => $this->evaluator->invert([new ColorNode('#ff0000')], ['space' => new StringNode('hsl')]))
                ->toThrow(UnsupportedColorSpaceException::class, "Unsupported color space 'hsl' in invert().")
                ->and(fn(): AstNode => $this->evaluator->invert([new ColorNode('#ff0000')], ['space' => new StringNode('srgb-linear')]))
                ->toThrow(UnsupportedColorSpaceException::class, "Unsupported color space 'srgb-linear' in invert().");
        });

        it('falls back to legacy inversion for modern colors without a space', function () {
            $result = $this->evaluator->invert(
                [new FunctionNode('oklab', [new ListNode([
                    new NumberNode(50, '%'),
                    new NumberNode(10),
                    new NumberNode(10),
                ], 'space')])],
                [],
            );

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('hsl');
        });
    });

    describe('hwb serialization', function () {
        it('converts overflowing hwb results into hsl colors', function () {
            $sumOverflow = $this->evaluator->adjustColor(
                [new FunctionNode('hwb', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(40, '%'),
                    new NumberNode(40, '%'),
                ], 'space')])],
                ['whiteness' => new NumberNode(50, '%')],
            );

            $rangeOverflow = $this->evaluator->changeColor(
                [new FunctionNode('hwb', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')])],
                ['whiteness' => new NumberNode(150, '%')],
            );

            expect($sumOverflow)->toBeInstanceOf(FunctionNode::class)
                ->and($sumOverflow->name)->toBe('hsl')
                ->and($sumOverflow->arguments[2]->value)->toBeCloseTo(69.2307692308, 0.0000001)
                ->and($rangeOverflow)->toBeInstanceOf(FunctionNode::class)
                ->and($rangeOverflow->name)->toBe('hsl')
                ->and($rangeOverflow->arguments[2]->value)->toBeCloseTo(75.0, 0.0000001);
        });

        it('collapses opaque integral hwb results into hex colors', function () {
            $result = $this->evaluator->changeColor(
                [new FunctionNode('hwb', [new ListNode([
                    new NumberNode(0),
                    new NumberNode(20, '%'),
                    new NumberNode(20, '%'),
                ], 'space')])],
                ['blackness' => new NumberNode(20, '%')],
            );

            expect($result)->toBeInstanceOf(ColorNode::class)
                ->and($result->value)->toBe('#cc3333');
        });
    });

    describe('mix interpolation edges', function () {
        it('accepts calc weights and non-string methods', function () {
            $calcWeight = $this->evaluator->mix(
                [new ColorNode('#000'), new ColorNode('#fff'), new FunctionNode('calc', [new NumberNode(50, '%')])],
                [],
            );

            $numberMethod = $this->evaluator->mix(
                [new ColorNode('#000'), new ColorNode('#fff'), new NumberNode(50, '%'), new NumberNode(1)],
                [],
            );

            expect($calcWeight)->toBeInstanceOf(FunctionNode::class)
                ->and($calcWeight->name)->toBe('rgb')
                ->and($numberMethod)->toBeInstanceOf(FunctionNode::class)
                ->and($numberMethod->name)->toBe('rgb');
        });

        it('rejects unsupported interpolation spaces', function () {
            expect(fn(): AstNode => $this->evaluator->mix(
                [new ColorNode('#000'), new ColorNode('#fff'), new NumberNode(50, '%'), new StringNode('bogus')],
                [],
            ))->toThrow(UnsupportedColorSpaceException::class, "Unsupported color space 'bogus' in mix().");
        });

        it('returns untouched operands for extreme weights', function () {
            $oklch = new FunctionNode('oklch', [new ListNode([
                new NumberNode(50, '%'),
                new NumberNode(0.2),
                new NumberNode(80, 'deg'),
            ], 'space')]);

            $zeroWeight = $this->evaluator->mix(
                [new ColorNode('#000'), $oklch, new NumberNode(0, '%'), new StringNode('oklch')],
                [],
            );

            $fullWeight = $this->evaluator->mix(
                [$oklch, new ColorNode('#fff'), new NumberNode(100, '%'), new StringNode('oklch')],
                [],
            );

            expect($zeroWeight)->toBeInstanceOf(FunctionNode::class)
                ->and($zeroWeight->name)->toBe('oklch')
                ->and($zeroWeight->arguments[0]->items[2]->value)->toBeCloseTo(80.0, 0.0000001)
                ->and($fullWeight)->toBeInstanceOf(FunctionNode::class)
                ->and($fullWeight->name)->toBe('oklch')
                ->and($fullWeight->arguments[0]->items[1]->value)->toBeCloseTo(0.2, 0.0000001);
        });

        it('interpolates hues along the longer path', function () {
            $result = $this->evaluator->mix([
                new FunctionNode('hsl', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')]),
                new FunctionNode('hsl', [new ListNode([
                    new NumberNode(90),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')]),
                new NumberNode(50, '%'),
                new StringNode('hsl longer hue'),
            ], []);

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('hsl')
                ->and($result->arguments[0]->value)->toBeCloseTo(285.0, 0.0000001);
        });
    });

    describe('adjust and change validation', function () {
        it('rejects unknown target spaces', function () {
            expect(fn(): AstNode => $this->evaluator->adjustColor([new ColorNode('#ff0000')], [
                'space' => new StringNode('bogus'),
                'red'   => new NumberNode(10),
            ]))->toThrow(UnsupportedColorSpaceException::class, "Unsupported color space 'bogus' in adjust-color().");
        });

        it('rejects none and non-numeric values outside change mode', function () {
            expect(fn(): AstNode => $this->evaluator->adjustColor([new ColorNode('#ff0000')], ['alpha' => new StringNode('none')]))
                ->toThrow(MissingFunctionArgumentsException::class, 'color() expects number arguments.')
                ->and(fn(): AstNode => $this->evaluator->adjustColor([new ColorNode('#ff0000')], ['red' => new StringNode('none')]))
                ->toThrow(MissingFunctionArgumentsException::class, 'color() expects number arguments.')
                ->and(fn(): AstNode => $this->evaluator->adjustColor([new ColorNode('#ff0000')], ['red' => new StringNode('abc')]))
                ->toThrow(MissingFunctionArgumentsException::class, 'color() expects number arguments.');
        });

        it('rejects channels that do not exist in the target space', function () {
            expect(fn(): AstNode => $this->evaluator->adjustColor([new ColorNode('#ff0000')], ['bogus' => new NumberNode(10)]))
                ->toThrow(UnknownColorChannelException::class, "Unknown rgb channel 'bogus'.");
        });
    });

    describe('missing channels and out of range adjustments', function () {
        it('preserves none channels through change and adjust operations', function () {
            $rgbMissing = $this->evaluator->changeColor(
                [new FunctionNode('rgb', [new ListNode([
                    new StringNode('none'),
                    new NumberNode(10),
                    new NumberNode(20),
                    new StringNode('/'),
                    new StringNode('none'),
                ], 'space')])],
                ['red' => new NumberNode(5)],
            );

            $srgbMissing = $this->evaluator->adjustColor(
                [new FunctionNode('color', [new ListNode([
                    new StringNode('srgb'),
                    new NumberNode(0.1),
                    new NumberNode(0.2),
                    new NumberNode(0.3),
                    new StringNode('/'),
                    new StringNode('none'),
                ], 'space')])],
                ['red' => new NumberNode(0.5)],
            );

            expect($rgbMissing)->toBeInstanceOf(FunctionNode::class)
                ->and($rgbMissing->name)->toBe('rgb')
                ->and($rgbMissing->arguments[0])->toBeInstanceOf(ListNode::class)
                ->and($rgbMissing->arguments[0]->items[0]->value)->toBe(5.0)
                ->and($rgbMissing->arguments[0]->items[4]->value)->toBe('none')
                ->and($srgbMissing)->toBeInstanceOf(FunctionNode::class)
                ->and($srgbMissing->name)->toBe('color')
                ->and($srgbMissing->arguments[0]->items[1]->value)->toBeCloseTo(0.6, 0.0000001)
                ->and($srgbMissing->arguments[0]->items[4]->value)->toBe('/')
                ->and($srgbMissing->arguments[0]->items[5]->value)->toBe('none');
        });

        it('keeps already out of range channels when adjusting further', function () {
            $aboveMax = $this->evaluator->adjustColor(
                [new FunctionNode('rgb', [new NumberNode(300), new NumberNode(0), new NumberNode(0)])],
                ['red' => new NumberNode(10)],
            );

            $belowMin = $this->evaluator->adjustColor(
                [new FunctionNode('rgb', [new NumberNode(-10), new NumberNode(0), new NumberNode(0)])],
                ['red' => new NumberNode(-5)],
            );

            expect($aboveMax)->toBeInstanceOf(FunctionNode::class)
                ->and($aboveMax->name)->toBe('hsl')
                ->and($aboveMax->originColorSpace)->toBe('rgb')
                ->and($aboveMax->arguments[2]->value)->toBeCloseTo(58.8235294118, 0.0000001)
                ->and($belowMin)->toBeInstanceOf(FunctionNode::class)
                ->and($belowMin->name)->toBe('hsl')
                ->and($belowMin->arguments[2]->value)->toBeCloseTo(-1.9607843137, 0.0000001);
        });
    });

    describe('scale channel ranges', function () {
        it('returns zero when scaling missing channels', function () {
            $result = $this->evaluator->scaleColor(
                [new FunctionNode('hsl', [new ListNode([
                    new StringNode('none'),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')])],
                ['hue' => new NumberNode(10, '%')],
            );

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('hsl')
                ->and($result->arguments[0]->value)->toBe(0.0);
        });

        it('leaves present hue untouched when scaling', function () {
            $result = $this->evaluator->scaleColor(
                [new FunctionNode('hsl', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')])],
                ['hue' => new NumberNode(10, '%')],
            );

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('hsl')
                ->and($result->arguments[0]->value)->toBe(120.0);
        });

        it('scales alpha within its unit range', function () {
            $result = $this->evaluator->scaleColor([new ColorNode('#ff0000')], ['alpha' => new NumberNode(10, '%')]);

            expect($result)->toBeInstanceOf(ColorNode::class)
                ->and($result->value)->toBe('red');
        });
    });

    describe('legacy adjust-hue paths', function () {
        it('falls back to rgb-derived hsl when legacy hsl channels are missing', function () {
            $result = $this->evaluator->adjustHue(
                [new FunctionNode('hsl', [new ListNode([
                    new StringNode('none'),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')]), new NumberNode(10)],
                null,
            );

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('rgb')
                ->and($result->arguments[0]->value)->toBeCloseTo(75.0, 0.0000001)
                ->and($result->arguments[2]->value)->toBeCloseTo(25.0, 0.0000001);
        });

        it('emits hsl for legacy hsl and hwb colors', function () {
            $fromHsl = $this->evaluator->adjustHue(
                [new FunctionNode('hsl', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(50, '%'),
                    new NumberNode(50, '%'),
                ], 'space')]), new NumberNode(10)],
                null,
            );

            $fromHwb = $this->evaluator->adjustHue(
                [new FunctionNode('hwb', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(20, '%'),
                    new NumberNode(30, '%'),
                ], 'space')]), new NumberNode(10)],
                null,
            );

            expect($fromHsl)->toBeInstanceOf(FunctionNode::class)
                ->and($fromHsl->name)->toBe('hsl')
                ->and($fromHsl->arguments[0]->value)->toBeCloseTo(130.0, 0.0000001)
                ->and($fromHwb)->toBeInstanceOf(FunctionNode::class)
                ->and($fromHwb->name)->toBe('hsl')
                ->and($fromHwb->arguments[0]->value)->toBeCloseTo(130.0, 0.0000001)
                ->and($fromHwb->arguments[1]->value)->toBeCloseTo(55.5555555556, 0.0000001)
                ->and($fromHwb->arguments[2]->value)->toBeCloseTo(45.0, 0.0000001);
        });

        it('treats missing whiteness and blackness channels as zero', function () {
            $missingWhiteness = $this->evaluator->adjustHue(
                [new FunctionNode('hwb', [new ListNode([
                    new NumberNode(120),
                    new StringNode('none'),
                    new NumberNode(30, '%'),
                ], 'space')]), new NumberNode(10)],
                null,
            );

            $missingBlackness = $this->evaluator->adjustHue(
                [new FunctionNode('hwb', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(20, '%'),
                    new StringNode('none'),
                ], 'space')]), new NumberNode(10)],
                null,
            );

            expect($missingWhiteness)->toBeInstanceOf(FunctionNode::class)
                ->and($missingWhiteness->name)->toBe('hsl')
                ->and($missingWhiteness->arguments[1]->value)->toBeCloseTo(100.0, 0.0000001)
                ->and($missingWhiteness->arguments[2]->value)->toBeCloseTo(35.0, 0.0000001)
                ->and($missingBlackness)->toBeInstanceOf(FunctionNode::class)
                ->and($missingBlackness->name)->toBe('hsl')
                ->and($missingBlackness->arguments[2]->value)->toBeCloseTo(60.0, 0.0000001);
        });

        it('falls back to rgb-derived hsl when hwb hue is missing', function () {
            $result = $this->evaluator->adjustHue(
                [new FunctionNode('hwb', [new ListNode([
                    new StringNode('none'),
                    new NumberNode(20, '%'),
                    new NumberNode(30, '%'),
                ], 'space')]), new NumberNode(10)],
                null,
            );

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('rgb')
                ->and($result->arguments[0]->value)->toBeCloseTo(70.0, 0.0000001)
                ->and($result->arguments[2]->value)->toBeCloseTo(20.0, 0.0000001);
        });

        it('zeroes hue for achromatic hwb colors', function () {
            $result = $this->evaluator->adjustHue(
                [new FunctionNode('hwb', [new ListNode([
                    new NumberNode(120),
                    new NumberNode(100, '%'),
                    new NumberNode(0, '%'),
                ], 'space')]), new NumberNode(10)],
                null,
            );

            expect($result)->toBeInstanceOf(FunctionNode::class)
                ->and($result->name)->toBe('hsl')
                ->and($result->arguments[0]->value)->toBe(0.0)
                ->and($result->arguments[1]->value)->toBe(0.0)
                ->and($result->arguments[2]->value)->toBeCloseTo(100.0, 0.0000001);
        });
    });
});
