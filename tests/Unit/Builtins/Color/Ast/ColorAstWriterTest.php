<?php

declare(strict_types=1);

use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;
use Bugo\SCSS\Builtins\Color\Adapters\IrisLiteralAdapter;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstWriter;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;

describe('ColorAstWriter', function () {
    beforeEach(function () {
        $this->serializer = new ColorAstWriter(new IrisConverterAdapter(), new IrisLiteralAdapter());
    });

    it('serializes whole rgb values as color nodes', function () {
        $node = $this->serializer->fromRgb(new RgbColor(255.0, 255.0, 255.0));

        expect($node)->toBeInstanceOf(ColorNode::class)
            ->and($node->value)->toBe('white');
    });

    it('serializes fractional rgb values as rgb functions', function () {
        $node = $this->serializer->serializeRgbResult(new RgbColor(10.5, 20.25, 30.75, 1.0));

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node instanceof FunctionNode ? $node->name : null)->toBe('rgb');
    });

    it('serializes normalized rgb values as legacy rgb functions', function () {
        $node = $this->serializer->serializeLegacyRgbFunction(new RgbColor(1.0, 0.5, 0.0, 1.0));

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->name)->toBe('rgb');
    });

    it('serializes legacy rgb values with alpha as rgba functions', function () {
        $node = $this->serializer->serializeLegacyRgbFunction(new RgbColor(1.0, 0.5, 0.0, 0.5));

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->name)->toBe('rgba')
            ->and($node->arguments[3])->toBeInstanceOf(NumberNode::class)
            ->and($node->arguments[3]->value)->toBe(0.5);
    });

    it('reuses color node serialization for byte rgb values from color sources', function () {
        $node = $this->serializer->serializeRgbFromAstSource(new ColorNode('#f00'), new RgbColor(255.0, 0.0, 0.0, 1.0));

        expect($node)->toBeInstanceOf(ColorNode::class)
            ->and($node->value)->toBe('#ff0000');
    });

    it('builds rgba function nodes when alpha is less than one', function () {
        $node = $this->serializer->buildRgbFunctionNode(10.0, 20.0, 30.0, 0.25);

        expect($node)->toBeInstanceOf(FunctionNode::class)
            ->and($node->name)->toBe('rgba')
            ->and($node->arguments[3])->toBeInstanceOf(NumberNode::class)
            ->and($node->arguments[3]->value)->toBe(0.25);
    });
});
