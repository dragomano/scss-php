<?php

declare(strict_types=1);

use Bugo\Iris\Converters\ModelConverter;
use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\LiteralSerializer;
use Bugo\Iris\Spaces\HwbColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Builtins\Color\Support\LchChannelData;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
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

    it('defers function-like raw color strings and throws for unsupported plain strings', function () {
        expect(fn() => $this->converter->toRgb(new StringNode('definitely-not-a-color(')))
            ->toThrow(DeferToCssFunctionException::class)
            ->and(fn() => $this->converter->toRgb(new StringNode('definitely-not-a-color')))
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

    it('treats unknown string functions as non-legacy colors', function () {
        $unknown   = new StringNode('color(display-p3 1 0 0)');
        $legacyRgb = new StringNode('rgba(1, 2, 3, 0.5)');
        $otherNode = new class extends AstNode {};

        expect($this->converter->isLegacyColor($unknown))->toBeFalse()
            ->and($this->converter->isLegacyColor($legacyRgb))->toBeTrue()
            ->and($this->converter->isLegacyColor($otherNode))->toBeFalse();
    });

    it('serializes color nodes and rgb functions from iris colors', function () {
        $colorNode  = $this->converter->fromRgb(new RgbColor(255.0, 255.0, 255.0));
        $fractional = $this->converter->serializeRgbResult(new RgbColor(10.5, 20.25, 30.75, 1.0));

        expect($colorNode)->toBeInstanceOf(ColorNode::class)
            ->and($colorNode->value)->toBe('white')
            ->and($fractional)->toBeInstanceOf(FunctionNode::class)
            ->and($fractional->name)->toBe('rgb');
    });

    it('returns a color node from serializeByteRgb when rgb has no fractional values', function () {
        $rgb    = new RgbColor(255.0, 0.0, 0.0, 1.0);
        $result = $this->converter->serializeByteRgb($rgb);

        expect($result)->toBeInstanceOf(ColorNode::class);
    });

    it('builds an rgba function node when alpha differs from 1', function () {
        $node = $this->converter->buildRgbFunctionNode(255.0, 0.0, 0.0, 0.5);

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->name)->toBe('rgba');
    });

    it('treats hex and paren-less strings as legacy colors', function () {
        expect($this->converter->isLegacyColor(new StringNode('#abc')))->toBeTrue()
            ->and($this->converter->isLegacyColor(new StringNode('red')))->toBeTrue();
    });

    it('throws unsupported color value for non-string color node with unknown literal', function () {
        expect(fn() => $this->converter->toRgb(new ColorNode('not-a-color')))
            ->toThrow(UnsupportedColorValueException::class, 'not-a-color');
    });

    it('extracts oklch mix data with missing channel flags from oklch function', function () {
        $oklch = new FunctionNode('oklch', [
            new ListNode([
                new StringNode('none'),
                new NumberNode(0.1),
                new StringNode('none'),
                new StringNode('/'),
                new NumberNode(0.5),
            ], 'space'),
        ]);

        $result = $this->converter->extractOklchMixData($oklch, 'color');

        expect($result)->toBeInstanceOf(LchChannelData::class)
            ->and($result->l)->toBe(0.0)
            ->and($result->lightnessMissing)->toBeTrue()
            ->and($result->chromaMissing)->toBeFalse()
            ->and($result->hueMissing)->toBeTrue()
            ->and($result->a)->toBe(0.5);
    });

    it('extracts oklch mix data by converting non-oklch colors', function () {
        $result = $this->converter->extractOklchMixData(new ColorNode('#ff0000'), 'color');

        expect($result)->toBeInstanceOf(LchChannelData::class)
            ->and($result->lightnessMissing)->toBeFalse()
            ->and($result->chromaMissing)->toBeFalse()
            ->and($result->hueMissing)->toBeFalse();
    });

    it('serializes out of gamut rgb as unclamped hsl', function () {
        $node = $this->converter->serializeRgbResult(new RgbColor(300.0, -10.0, 128.0, 0.5));

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->name)->toBe('hsla');
    });

    it('serializes as oklch string with zero chroma as percent', function () {
        $zeroChroma    = $this->converter->serializeAsOklchString(new OklchColor(0.5, 0.0, 180.0));
        $percentChroma = $this->converter->serializeAsOklchString(new OklchColor(0.5, 0.0, 180.0), true);
        $normal        = $this->converter->serializeAsOklchString(new OklchColor(0.5, 0.1, 180.0), true);

        $chromaNode = fn(FunctionNode $node): AstNode => $node->arguments[0]->items[1];

        expect($chromaNode($zeroChroma)->value)->toBe(0.0)
            ->and($chromaNode($percentChroma)->value)->toBe(0)
            ->and($chromaNode($percentChroma)->unit)->toBe('%')
            ->and($chromaNode($normal)->value)->toBe(0.1)
            ->and($chromaNode($normal)->unit)->toBeNull();
    });

    it('builds oklab color node with none channels', function () {
        $withChannels = $this->converter->buildOklabColorNodeWithNone([
            'l' => 50.0, 'a' => 10.0, 'b' => null, 'alpha' => 0.5,
        ]);
        $allNone = $this->converter->buildOklabColorNodeWithNone([
            'l' => null, 'a' => null, 'b' => null, 'alpha' => 1.0,
        ]);

        $rendered = static function (FunctionNode $node): string {
            /** @var ListNode $list */
            $list = $node->arguments[0];

            return implode(' ', array_map(
                static fn(AstNode $item): string => $item instanceof NumberNode
                    ? $item->value . ($item->unit ?? '')
                    : $item->__toString(),
                $list->items,
            ));
        };

        expect($withChannels->name)->toBe('oklab')
            ->and($rendered($withChannels))->toBe('50% 10 none / 0.5')
            ->and($allNone->name)->toBe('oklab')
            ->and($rendered($allNone))->toBe('none none none');
    });

    it('builds lab color node with none channels', function () {
        $node = $this->converter->buildLabColorNodeWithNone([
            'l' => null, 'a' => 10.0, 'b' => null, 'alpha' => 0.5,
        ]);

        /** @var ListNode $list */
        $list = $node->arguments[0];

        expect($node->name)->toBe('lab')
            ->and($list->items[0]->value)->toBe('none')
            ->and($list->items[1]->value)->toBe(10.0)
            ->and($list->items[2]->value)->toBe('none')
            ->and($list->items[4]->value)->toBe(0.5);
    });

    it('builds lch color node with none channels', function () {
        $withValues = $this->converter->buildLchColorNodeWithNone(50.0, 0.1, 180.0, 0.5);
        $withNone   = $this->converter->buildLchColorNodeWithNone(null, null, null, 1.0);

        /** @var ListNode $withValuesList */
        $withValuesList = $withValues->arguments[0];

        /** @var ListNode $withNoneList */
        $withNoneList = $withNone->arguments[0];

        expect($withValues->name)->toBe('lch')
            ->and($withValuesList->items[0]->value)->toBe(50.0)
            ->and($withValuesList->items[0]->unit)->toBe('%')
            ->and($withValuesList->items[1]->value)->toBe(0.1)
            ->and($withValuesList->items[2]->value)->toBe(180.0)
            ->and($withNone->name)->toBe('lch')
            ->and($withNoneList->items[0]->value)->toBe('none')
            ->and($withNoneList->items[1]->value)->toBe('none')
            ->and($withNoneList->items[2]->value)->toBe('none');
    });

    it('builds modern rgb function node with none channels and alpha tails', function () {
        $withNone = $this->converter->buildModernRgbFunctionNode([null, 128.0, 255.0], null);
        $partial  = $this->converter->buildModernRgbFunctionNode([10.0, 20.0, 30.0], 0.5);
        $full     = $this->converter->buildModernRgbFunctionNode([10.0, 20.0, 30.0], 1.0);

        /** @var ListNode $withNoneList */
        $withNoneList = $withNone->arguments[0];

        /** @var ListNode $partialList */
        $partialList = $partial->arguments[0];

        /** @var ListNode $fullList */
        $fullList = $full->arguments[0];

        expect($withNone->name)->toBe('rgb')
            ->and($withNoneList->items[0]->value)->toBe('none')
            ->and($withNoneList->items[3]->value)->toBe('/')
            ->and($withNoneList->items[4]->value)->toBe('none')
            ->and($partialList->items)->toHaveCount(5)
            ->and($partialList->items[4]->value)->toBe(0.5)
            ->and($fullList->items)->toHaveCount(3);
    });

    it('builds modern hsl function node with none channels and clamped saturation', function () {
        $withNone     = $this->converter->buildModernHslFunctionNode([null, -50.0, null], 0.5);
        $nanSaturation = $this->converter->buildModernHslFunctionNode([NAN, NAN, 50.0], 1.0);

        /** @var ListNode $withNoneList */
        $withNoneList = $withNone->arguments[0];

        /** @var ListNode $nanList */
        $nanList = $nanSaturation->arguments[0];

        expect($withNone->name)->toBe('hsl')
            ->and($withNoneList->items[0]->value)->toBe('none')
            ->and($withNoneList->items[1]->value)->toBe(0.0)
            ->and($withNoneList->items[2]->value)->toBe('none')
            ->and($nanList->items[1]->value)->toBe(0.0)
            ->and($nanList->items[2]->value)->toBe(50.0);
    });

    it('parses alpha through public wrapper', function () {
        expect($this->converter->parseAlphaPublic(null, 'color'))->toBe(1.0)
            ->and($this->converter->parseAlphaPublic(new NumberNode(0.3), 'color'))->toBe(0.3);
    });
});
