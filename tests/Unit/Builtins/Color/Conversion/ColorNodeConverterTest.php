<?php

declare(strict_types=1);

use Bugo\Iris\Converters\ModelConverter;
use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\LiteralSerializer;
use Bugo\Iris\Spaces\HwbColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorNodeConverter', function () {
    beforeEach(function () {
        $runtime = new ColorRuntime(
            context: new ColorModuleContext(
                errorCtx: static fn(string $name): string => $name,
                isGlobalBuiltinCall: static fn(): bool => false,
                warn: static function (): void {},
            ),
            spaceConverter: new SpaceConverter(),
            modelConverter: new ModelConverter(),
            literalParser: new LiteralParser(),
            literalSerializer: new LiteralSerializer(),
        );

        $this->converter = new ColorNodeConverter($runtime);
    });

    it('throws for unsupported raw color strings and can parse function strings', function () {
        expect(fn() => $this->converter->toRgb(new StringNode('definitely-not-a-color(')))
            ->toThrow(UnsupportedColorValueException::class);

        $parsed = $this->converter->parseColorString('rgb(10 20 30 / 40%)');

        expect($parsed)->toBeInstanceOf(FunctionNode::class);
    });

    it('reports detected generic space for unsupported color functions', function () {
        $color = new FunctionNode('color', [new StringNode('display-p3')]);

        expect(fn() => $this->converter->toRgb($color))
            ->toThrow(UnsupportedColorValueException::class, "Unsupported color value 'display-p3'.");
    });

    it('throws for missing color arguments when node is not a color literal or string', function () {
        expect(fn() => $this->converter->toRgb(new NumberNode(1)))
            ->toThrow(MissingFunctionArgumentsException::class, 'color() expects color arguments.');
    });

    it('uses direct xyz and alpha extraction for function colors', function () {
        $rgba = new FunctionNode('rgba', [
            new NumberNode(255),
            new NumberNode(0),
            new NumberNode(0),
            new NumberNode(0.25),
        ]);

        $lab = new FunctionNode('lab', [
            new NumberNode(50.0),
            new NumberNode(10.0),
            new NumberNode(20.0),
        ]);

        $alpha  = $this->converter->toAlpha($rgba);
        $xyzD65 = $this->converter->toXyzD65($rgba);
        $xyzD50 = $this->converter->toXyzD50($lab);

        expect($alpha)->toBe(0.25)
            ->and($xyzD65)->toBeInstanceOf(XyzColor::class)
            ->and($xyzD50)->toBeInstanceOf(XyzColor::class);
    });

    it('builds hwb colors and preserves source alpha', function () {
        $hwb = $this->converter->toHwb(new FunctionNode('rgba', [
            new NumberNode(255),
            new NumberNode(0),
            new NumberNode(0),
            new NumberNode(0.5),
        ]));

        expect($hwb)->toBeInstanceOf(HwbColor::class)
            ->and($hwb->a)->toBe(0.5);
    });

    it('detects generic color spaces from arguments', function () {
        $missingSpace = new FunctionNode('color', [new NumberNode(1.0)]);
        $xyzD65       = new FunctionNode('color', [new StringNode('xYz-D65')]);
        $displayP3    = new FunctionNode('color', [new StringNode('Display-P3')]);

        expect($this->converter->detectGenericColorSpace($missingSpace))->toBe('srgb')
            ->and($this->converter->detectGenericColorSpace($xyzD65))->toBe('xyz')
            ->and($this->converter->detectGenericColorSpace($displayP3))->toBe('display-p3');
    });

    it('detects native color spaces for hwb and color functions', function () {
        $hwb = new FunctionNode('HWBA', [
            new NumberNode(120.0),
            new NumberNode(10.0, '%'),
            new NumberNode(20.0, '%'),
            new NumberNode(0.5),
        ]);
        $generic = new FunctionNode('color', [new StringNode('xyz-d65')]);

        expect($this->converter->detectNativeColorSpace($hwb))->toBe('hwb')
            ->and($this->converter->detectNativeColorSpace($generic))->toBe('xyz');
    });

    it('short-circuits gamut checks for legacy, non-function, perceptual and xyz colors', function () {
        $legacy      = new ColorNode('#abc');
        $plainString = new StringNode('not-a-function');

        $lab = new FunctionNode('lab', [
            new NumberNode(50.0),
            new NumberNode(0.0),
            new NumberNode(0.0),
        ]);

        $xyz = new FunctionNode('color', [
            new StringNode('xyz-d50'),
            new NumberNode(0.1),
            new NumberNode(0.2),
            new NumberNode(0.3),
        ]);

        expect($this->converter->isInGamut($legacy))->toBeTrue()
            ->and($this->converter->isInGamut($plainString))->toBeTrue()
            ->and($this->converter->isInGamut($lab))->toBeTrue()
            ->and($this->converter->isInGamut($xyz))->toBeTrue();
    });

    it('treats unknown string functions as non-legacy colors', function () {
        $unknown   = new StringNode('color(display-p3 1 0 0)');
        $legacyRgb = new StringNode('rgba(1, 2, 3, 0.5)');
        $otherNode = new class extends AstNode {};

        expect($this->converter->isLegacyColor($unknown))->toBeFalse()
            ->and($this->converter->isInGamut($unknown))->toBeTrue()
            ->and($this->converter->isLegacyColor($legacyRgb))->toBeTrue()
            ->and($this->converter->isLegacyColor($otherNode))->toBeFalse();
    });

    it('short-circuits gamut checks for non-color functions and rejects out of range percentages', function () {
        $deviceCmyk = new FunctionNode('device-cmyk', [new NumberNode(1.2)]);
        $displayP3  = new FunctionNode('color', [
            new StringNode('display-p3'),
            new NumberNode(120.0, '%'),
            new NumberNode(0.5),
            new NumberNode(0.25),
        ]);

        expect($this->converter->isInGamut($deviceCmyk))->toBeTrue()
            ->and($this->converter->isInGamut($displayP3))->toBeFalse();
    });

    it('ignores missing generic color channels during gamut checks', function () {
        $displayP3 = new FunctionNode('color', [
            new ListNode([
                new StringNode('display-p3'),
                new StringNode('none'),
                new NumberNode(0.5),
                new NumberNode(0.25),
            ], 'space'),
        ]);

        expect($this->converter->isInGamut($displayP3))->toBeTrue();
    });

    it('converts srgb percentages to unclamped rgb bytes', function () {
        $color = new FunctionNode('color', [new ListNode([
            new StringNode('srgb'),
            new NumberNode(50.0, '%'),
            new NumberNode(25.0, '%'),
            new NumberNode(0.0, '%'),
            new StringNode('/'),
            new NumberNode(0.4),
        ])]);

        $rgb = $this->converter->toUnclampedRgb($color);

        expect($rgb->r)->toBe(127.5)
            ->and($rgb->g)->toBe(63.75)
            ->and($rgb->b)->toBe(0.0)
            ->and($rgb->a)->toBe(0.4);
    });

    it('reads native lab colors and extracts srgb channels', function () {
        $lab = $this->converter->readNativeLab(new FunctionNode('lab', [
            new ListNode([
                new NumberNode(50.0, '%'),
                new NumberNode(10.0),
                new NumberNode(-5.0),
            ], 'space'),
        ]));

        [$red, $green, $blue] = $this->converter->extractSrgbChannels(new FunctionNode('color', [
            new ListNode([
                new StringNode('srgb'),
                new NumberNode(0.1),
                new NumberNode(0.2),
                new NumberNode(0.3),
            ], 'space'),
        ]));

        expect($lab)->toBeInstanceOf(LabColor::class)
            ->and($lab->l)->toBe(50.0)
            ->and($lab->a)->toBe(10.0)
            ->and($lab->b)->toBe(-5.0)
            ->and($red)->toBe(0.1)
            ->and($green)->toBe(0.2)
            ->and($blue)->toBe(0.3);
    });

    it('serializes color nodes and rgb functions from iris colors', function () {
        $colorNode  = $this->converter->fromRgb(new RgbColor(255.0, 255.0, 255.0));
        $fractional = $this->converter->serializeRgbResult(new RgbColor(10.5, 20.25, 30.75, 1.0));
        $legacy     = $this->converter->serializeLegacyRgbFunction(new RgbColor(1.0, 0.5, 0.0, 0.5));

        expect($colorNode)->toBeInstanceOf(ColorNode::class)
            ->and($colorNode->value)->toBe('white')
            ->and($fractional)->toBeInstanceOf(FunctionNode::class)
            ->and($fractional->name)->toBe('rgb')
            ->and($legacy)->toBeInstanceOf(FunctionNode::class)
            ->and($legacy->name)->toBe('rgba');
    });

    it('extracts srgb channels by converting non-color function nodes to rgb', function () {
        $rgba = new FunctionNode('rgba', [
            new NumberNode(255),
            new NumberNode(0),
            new NumberNode(0),
            new NumberNode(1.0),
        ]);

        [$r, $g, $b] = $this->converter->extractSrgbChannels($rgba);

        expect($r)->toBe(1.0)
            ->and($g)->toBe(0.0)
            ->and($b)->toBe(0.0);
    });

    it('returns a color node from serializeRgbFromAstSource when source is a ColorNode and rgb has no fractional values', function () {
        $source = new ColorNode('#ff0000');
        $rgb    = new RgbColor(255.0, 0.0, 0.0, 1.0);

        $result = $this->converter->serializeRgbFromAstSource($source, $rgb);

        expect($result)->toBeInstanceOf(ColorNode::class);
    });

    it('builds an rgba function node when alpha differs from 1', function () {
        $node = $this->converter->buildRgbFunctionNode(255.0, 0.0, 0.0, 0.5);

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->name)->toBe('rgba');
    });

    it('parses explicit alpha from oklch node with slash-separated alpha channel', function () {
        $oklch = new FunctionNode('oklch', [
            new ListNode([
                new NumberNode(50.0, '%'),
                new NumberNode(0.1),
                new NumberNode(180.0),
                new StringNode('/'),
                new NumberNode(0.5),
            ], 'space'),
        ]);

        $result = $this->converter->extractOklch($oklch, 'color');

        expect($result)->toBeInstanceOf(OklchColor::class)
            ->and($result->a)->toBe(0.5);
    });
});
