<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('CssColorFunctionConverter', function () {
    beforeEach(function () {
        $this->converter = new CssColorFunctionConverter();
    });

    it('converts rgb functions to rgba', function () {
        $rgb = $this->converter->tryConvertToRgba(new FunctionNode('rgb', [
            new NumberNode(255),
            new NumberNode(0),
            new NumberNode(0),
        ]));

        expect($rgb)->not->toBeNull()
            ->and($rgb?->r)->toBe(1.0)
            ->and($rgb?->g)->toBe(0.0)
            ->and($rgb?->b)->toBe(0.0)
            ->and($rgb?->a)->toBe(1.0);
    });

    it('converts color(srgb ...) functions to xyz d65', function () {
        $xyz = $this->converter->tryConvertToXyzD65(new FunctionNode('color', [
            new StringNode('srgb'),
            new NumberNode(0.1),
            new NumberNode(0.2),
            new NumberNode(0.3),
        ]));

        expect($xyz)->not->toBeNull()
            ->and($xyz[0]->x)->toBeGreaterThan(0.0)
            ->and($xyz[0]->y)->toBeGreaterThan(0.0)
            ->and($xyz[0]->z)->toBeGreaterThan(0.0)
            ->and($xyz[1])->toBe(1.0);
    });

    it('returns null for unsupported channel layout', function () {
        expect($this->converter->tryConvertToRgba(new FunctionNode('rgb', [new StringNode('oops')])))
            ->toBeNull();
    });
});
