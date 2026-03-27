<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\HwbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;
use Bugo\SCSS\Builtins\Color\Adapters\IrisLiteralAdapter;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstParser;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;
use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorNodeConverter', function () {
    beforeEach(function () {
        $irisConverter = new IrisConverterAdapter();
        $hexConverter = new HexColorConverter();

        $this->converter = new ColorNodeConverter(
            $hexConverter,
            new CssColorFunctionConverter(),
            $irisConverter,
            new IrisLiteralAdapter(),
            new ColorAstParser(),
            static fn(string $name): string => $name,
        );
    });

    it('throws for unsupported raw color strings and can parse function strings', function () {
        expect(fn() => $this->converter->toRgb(new StringNode('definitely-not-a-color(')))
            ->toThrow(UnsupportedColorValueException::class);

        $parsed = $this->converter->parseColorString('rgb(10 20 30 / 40%)');

        expect($parsed)->toBeInstanceOf(FunctionNode::class);
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

        $alpha = $this->converter->toAlpha($rgba);
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
        $xyzD65 = new FunctionNode('color', [new StringNode('xYz-D65')]);
        $displayP3 = new FunctionNode('color', [new StringNode('Display-P3')]);

        expect($this->converter->detectGenericColorSpace($missingSpace))->toBe('srgb')
            ->and($this->converter->detectGenericColorSpace($xyzD65))->toBe('xyz')
            ->and($this->converter->detectGenericColorSpace($displayP3))->toBe('display-p3');
    });

    it('short-circuits gamut checks for legacy, non-function, perceptual and xyz colors', function () {
        $legacy = new ColorNode('#abc');
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
});
