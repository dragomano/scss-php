<?php

declare(strict_types=1);

use Bugo\Iris\Converters\ModelConverter;
use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\LiteralSerializer;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Operations\ColorChannelInspector;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Exceptions\UnknownColorChannelException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorChannelReader', function () {
    beforeEach(function () {
        $state = new class {
            public bool $isGlobal = false;

            /** @var list<string> */
            public array $warnings = [];
        };
        $runtime = new ColorRuntime(
            context: new ColorModuleContext(
                errorCtx: static fn(string $name): string => $name,
                isGlobalBuiltinCall: fn(): bool => $state->isGlobal,
                warn: function ($context, string $message) use ($state): void {
                    $state->warnings[] = $message;
                },
            ),
            spaceConverter: new SpaceConverter(),
            modelConverter: new ModelConverter(),
            literalParser: new LiteralParser(),
            literalSerializer: new LiteralSerializer(),
        );

        $converter = new ColorNodeConverter($runtime);

        $this->state  = $state;
        $this->reader = new ColorChannelInspector($runtime, $converter);
    });

    it('reads channels using positional spaces and rgb-like alpha branches', function () {
        $fromChannel = $this->reader->channel([
            new ColorNode('#33669980'),
            new StringNode('green'),
            new StringNode('srgb'),
        ], []);

        $rgbAlpha  = $this->reader->resolveChannelValue(new ColorNode('#33669980'), 'rgb', 'alpha');
        $srgbGreen = $this->reader->resolveChannelValue(new ColorNode('#33669980'), 'srgb', 'green');
        $srgbBlue  = $this->reader->resolveChannelValue(new ColorNode('#33669980'), 'srgb', 'blue');
        $srgbAlpha = $this->reader->resolveChannelValue(new ColorNode('#33669980'), 'srgb', 'alpha');
        $hslAlpha  = $this->reader->resolveChannelValue(new ColorNode('#33669980'), 'hsl', 'alpha');
        $hwbHue    = $this->reader->resolveChannelValue(new ColorNode('#33669980'), 'hwb', 'hue');
        $hwbAlpha  = $this->reader->resolveChannelValue(new ColorNode('#33669980'), 'hwb', 'alpha');

        expect($fromChannel->value)->toBeCloseTo(102 / 255, 0.000001)
            ->and($rgbAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($srgbGreen->value)->toBeCloseTo(102 / 255, 0.000001)
            ->and($srgbBlue->value)->toBeCloseTo(153 / 255, 0.000001)
            ->and($srgbAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($hslAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($hwbHue->unit)->toBe('deg')
            ->and($hwbAlpha->value)->toBeCloseTo(0.501961, 0.00001);
    });

    it('reads perceptual and xyz channels across supported spaces', function () {
        $color = new ColorNode('#33669980');

        $lchLightness = $this->reader->resolveChannelValue($color, 'lch', 'lightness');
        $lchChroma    = $this->reader->resolveChannelValue($color, 'lch', 'chroma');
        $lchHue       = $this->reader->resolveChannelValue($color, 'lch', 'hue');
        $lchAlpha     = $this->reader->resolveChannelValue($color, 'lch', 'alpha');

        $labLightness = $this->reader->resolveChannelValue($color, 'lab', 'lightness');
        $labA         = $this->reader->resolveChannelValue($color, 'lab', 'a');
        $labB         = $this->reader->resolveChannelValue($color, 'lab', 'b');
        $labAlpha     = $this->reader->resolveChannelValue($color, 'lab', 'alpha');

        $oklchLightness = $this->reader->resolveChannelValue($color, 'oklch', 'lightness');
        $oklchChroma    = $this->reader->resolveChannelValue($color, 'oklch', 'chroma');
        $oklchHue       = $this->reader->resolveChannelValue($color, 'oklch', 'hue');
        $oklchAlpha     = $this->reader->resolveChannelValue($color, 'oklch', 'alpha');

        $oklabLightness = $this->reader->resolveChannelValue($color, 'oklab', 'lightness');
        $oklabA         = $this->reader->resolveChannelValue($color, 'oklab', 'a');
        $oklabB         = $this->reader->resolveChannelValue($color, 'oklab', 'b');
        $oklabAlpha     = $this->reader->resolveChannelValue($color, 'oklab', 'alpha');

        $xyzX     = $this->reader->resolveChannelValue($color, 'xyz', 'x');
        $xyzY     = $this->reader->resolveChannelValue($color, 'xyz-d65', 'y');
        $xyzZ     = $this->reader->resolveChannelValue($color, 'xyz-d65', 'z');
        $xyzAlpha = $this->reader->resolveChannelValue($color, 'xyz-d65', 'alpha');

        $xyzD50X     = $this->reader->resolveChannelValue($color, 'xyz-d50', 'x');
        $xyzD50Y     = $this->reader->resolveChannelValue($color, 'xyz-d50', 'y');
        $xyzD50Z     = $this->reader->resolveChannelValue($color, 'xyz-d50', 'z');
        $xyzD50Alpha = $this->reader->resolveChannelValue($color, 'xyz-d50', 'alpha');

        expect($lchLightness->unit)->toBe('%')
            ->and($lchChroma->value)->toBeFloat()
            ->and($lchHue->unit)->toBe('deg')
            ->and($lchAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($labLightness->unit)->toBe('%')
            ->and($labA->value)->toBeFloat()
            ->and($labB->value)->toBeFloat()
            ->and($labAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($oklchLightness->unit)->toBe('%')
            ->and($oklchChroma->value)->toBeFloat()
            ->and($oklchHue->unit)->toBe('deg')
            ->and($oklchAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($oklabLightness->unit)->toBe('%')
            ->and($oklabA->value)->toBeFloat()
            ->and($oklabB->value)->toBeFloat()
            ->and($oklabAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($xyzX->value)->toBeFloat()
            ->and($xyzY->value)->toBeFloat()
            ->and($xyzZ->value)->toBeFloat()
            ->and($xyzAlpha->value)->toBeCloseTo(0.501961, 0.00001)
            ->and($xyzD50X->value)->toBeFloat()
            ->and($xyzD50Y->value)->toBeFloat()
            ->and($xyzD50Z->value)->toBeFloat()
            ->and($xyzD50Alpha->value)->toBeCloseTo(0.501961, 0.00001);
    });

    it('throws for unknown channel names in resolved spaces', function () {
        $color = new ColorNode('#336699');

        expect(fn() => $this->reader->resolveChannelValue($color, 'lch', 'bogus'))
            ->toThrow(UnknownColorChannelException::class)
            ->and(fn() => $this->reader->resolveChannelValue($color, 'lab', 'bogus'))
            ->toThrow(UnknownColorChannelException::class)
            ->and(fn() => $this->reader->resolveChannelValue($color, 'oklch', 'bogus'))
            ->toThrow(UnknownColorChannelException::class)
            ->and(fn() => $this->reader->resolveChannelValue($color, 'oklab', 'bogus'))
            ->toThrow(UnknownColorChannelException::class)
            ->and(fn() => $this->reader->resolveChannelValue($color, 'xyz-d65', 'bogus'))
            ->toThrow(UnknownColorChannelException::class)
            ->and(fn() => $this->reader->resolveChannelValue($color, 'xyz-d50', 'bogus'))
            ->toThrow(UnknownColorChannelException::class);
    });

    it('rethrows unsupported color values for non-global channel alpha calls', function () {
        expect(fn() => $this->reader->channelAlpha([new StringNode('definitely-not-a-color(')], 'alpha', null))
            ->toThrow(UnsupportedColorValueException::class);
    });

    it('parses string colors in is-missing and returns false for unknown or absent channel slots', function () {
        $parsedString = $this->reader->isMissing([
            new StringNode('hsl(none 50% 50%)'),
            new StringNode('hue'),
        ]);

        $unknownChannel = $this->reader->isMissing([
            new FunctionNode('color', [new StringNode('srgb')]),
            new StringNode('hue'),
        ]);

        $missingSlot = $this->reader->isMissing([
            new FunctionNode('rgb', [new NumberNode(1), new NumberNode(2), new NumberNode(3)]),
            new StringNode('alpha'),
        ]);

        expect($parsedString)->toBeInstanceOf(BooleanNode::class)
            ->and($parsedString->value)->toBeTrue()
            ->and($unknownChannel->value)->toBeFalse()
            ->and($missingSlot->value)->toBeFalse();
    });

    it('detects powerless channels in hsl hwb lch and oklch spaces', function () {
        $hsl = $this->reader->isPowerless([
            new FunctionNode('hsl', [
                new NumberNode(0, 'deg'),
                new NumberNode(50, '%'),
                new NumberNode(0, '%'),
            ]),
            new StringNode('saturation'),
        ], []);

        $hwb = $this->reader->isPowerless([
            new FunctionNode('hwb', [
                new NumberNode(0, 'deg'),
                new NumberNode(60, '%'),
                new NumberNode(40, '%'),
            ]),
            new StringNode('hue'),
        ], []);

        $lch = $this->reader->isPowerless([
            new FunctionNode('lch', [
                new NumberNode(50, '%'),
                new NumberNode(0),
                new NumberNode(30, 'deg'),
            ]),
            new StringNode('hue'),
            new StringNode('lch'),
        ], []);

        $oklch = $this->reader->isPowerless([
            new FunctionNode('oklch', [
                new NumberNode(50, '%'),
                new NumberNode(0),
                new NumberNode(30, 'deg'),
            ]),
            new StringNode('hue'),
            new StringNode('oklch'),
        ], []);

        expect($hsl->value)->toBeTrue()
            ->and($hwb->value)->toBeTrue()
            ->and($lch->value)->toBeTrue()
            ->and($oklch->value)->toBeTrue();
    });

    it('detects powerless hwb hue for colors resolved through explicit hwb space', function () {
        $result = $this->reader->isPowerless([
            new ColorNode('#ffffff'),
            new StringNode('hue'),
        ], [
            'space' => new StringNode('hwb'),
        ]);

        expect($result)->toBeInstanceOf(BooleanNode::class)
            ->and($result->value)->toBeTrue();
    });
});
