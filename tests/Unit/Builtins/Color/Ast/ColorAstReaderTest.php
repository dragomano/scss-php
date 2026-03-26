<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;
use Bugo\SCSS\Builtins\Color\Adapters\IrisLiteralAdapter;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstParser;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstReader;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;
use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('ColorAstReader', function () {
    beforeEach(function () {
        $irisConverter = new IrisConverterAdapter();
        $irisLiteral   = new IrisLiteralAdapter();

        $converter = new ColorNodeConverter(
            new HexColorConverter(),
            new CssColorFunctionConverter(),
            $irisConverter,
            $irisLiteral,
            new ColorAstParser(),
            static fn(string $name): string => $name,
        );

        $this->bridge = new ColorAstReader(
            new ColorArgumentParser($irisConverter, static fn(string $name): string => $name),
            $converter,
            new HexColorConverter(),
            $irisConverter,
        );
    });

    it('reads native lab colors', function () {
        $lab = $this->bridge->readNativeLab(new FunctionNode('lab', [
            new ListNode([
                new NumberNode(50.0, '%'),
                new NumberNode(10.0),
                new NumberNode(-5.0),
            ], 'space'),
        ]));

        expect($lab->l)->toBe(50.0)
            ->and($lab->a)->toBe(10.0)
            ->and($lab->b)->toBe(-5.0);
    });

    it('extracts srgb channels from color function', function () {
        [$red, $green, $blue] = $this->bridge->extractSrgbChannels(new FunctionNode('color', [
            new ListNode([
                new StringNode('srgb'),
                new NumberNode(0.1),
                new NumberNode(0.2),
                new NumberNode(0.3),
            ], 'space'),
        ]));

        expect($red)->toBe(0.1)
            ->and($green)->toBe(0.2)
            ->and($blue)->toBe(0.3);
    });
});
