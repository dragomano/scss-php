<?php

declare(strict_types=1);

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
});
