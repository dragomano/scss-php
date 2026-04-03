<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;
use Bugo\SCSS\Builtins\Color\Adapters\IrisLiteralAdapter;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstParser;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstReader;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstWriter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceInterop;
use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;
use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorChannelSchema;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorSpaceInterop', function () {
    beforeEach(function () {
        $irisConverter = new IrisConverterAdapter();
        $irisLiteral   = new IrisLiteralAdapter();
        $hexConverter  = new HexColorConverter();

        $converter = new ColorNodeConverter(
            $hexConverter,
            new CssColorFunctionConverter(),
            $irisConverter,
            $irisLiteral,
            new ColorAstParser(),
            static fn(string $name): string => $name,
        );

        $this->interop = new ColorSpaceInterop(
            new ColorArgumentParser($irisConverter, static fn(string $name): string => $name),
            $converter,
            new ColorAstWriter($irisConverter, $irisLiteral),
            new ColorAstReader(
                new ColorArgumentParser($irisConverter, static fn(string $name): string => $name),
                $converter,
                $hexConverter,
                $irisConverter,
            ),
            $hexConverter,
            $irisConverter,
            new ColorChannelSchema(),
            static fn(string $name): string => $name,
        );
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

    it('converts colors to xyz-d50 and wide-gamut generic spaces', function () {
        $xyzD50 = $this->interop->toSpace([new ColorNode('#036'), new StringNode('xyz-d50')]);
        $displayP3Linear = $this->interop->toSpace([new ColorNode('#036'), new StringNode('display-p3-linear')]);
        $a98 = $this->interop->toSpace([new ColorNode('#036'), new StringNode('a98-rgb')]);
        $prophoto = $this->interop->toSpace([new ColorNode('#036'), new StringNode('prophoto-rgb')]);
        $rec2020 = $this->interop->toSpace([new ColorNode('#036'), new StringNode('rec2020')]);

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

    it('rejects unknown gamut mapping methods and unsupported target spaces', function () {
        expect(fn() => $this->interop->toGamut([
            new ColorNode('#036'),
            new StringNode('rgb'),
            new StringNode('weird'),
        ], []))->toThrow(UnsupportedColorValueException::class, 'Unknown gamut mapping method: weird')
            ->and(fn() => $this->interop->toGamut([
                new ColorNode('#036'),
                new StringNode('display-p3'),
            ], []))->toThrow(UnsupportedColorSpaceException::class);
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

        expect($result)->toBeInstanceOf(ColorNode::class)
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

        expect($result)->toBeInstanceOf(ColorNode::class)
            ->and($result)->not->toBe($color);
    });

    it('serializes rgb colors for original spaces', function () {
        $rgb = new RgbColor(300.0, 20.0, 10.0, 0.5);

        $srgb = $this->interop->serializeRgbForOriginalSpace('srgb', $rgb);
        $lab = $this->interop->serializeRgbForOriginalSpace('lab', $rgb);
        $displayP3 = $this->interop->serializeRgbForOriginalSpace('display-p3', $rgb);

        expect($srgb)->toBeInstanceOf(ColorNode::class)
            ->and($lab)->toBeInstanceOf(FunctionNode::class)
            ->and($lab->name)->toBe('lab')
            ->and($displayP3)->toBeInstanceOf(ColorNode::class);
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

        $a98 = $this->interop->rgbToWorkingSpaceChannels($rgb, 'a98-rgb');
        $prophoto = $this->interop->rgbToWorkingSpaceChannels($rgb, 'prophoto-rgb');
        $rec2020 = $this->interop->rgbToWorkingSpaceChannels($rgb, 'rec2020');

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
});
