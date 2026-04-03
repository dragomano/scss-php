<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

describe('HexColorConverter', function () {
    beforeEach(function () {
        $this->converter = new HexColorConverter();
    });

    it('returns null when the css function converter cannot produce rgba', function () {
        $result = $this->converter->tryConvert(new FunctionNode('device-cmyk', [
            new NumberNode(0.1),
            new NumberNode(0.2),
            new NumberNode(0.3),
            new NumberNode(0.4),
        ]));

        expect($result)->toBeNull();
    });

    it('rejects rgb functions that do not have exactly three channels', function () {
        $result = $this->converter->tryConvert(new FunctionNode('rgb', [
            new NumberNode(255, ''),
            new NumberNode(0, ''),
        ]));

        expect($result)->toBeNull();
    });

    it('rejects rgb functions with non-numeric channels', function () {
        $result = $this->converter->tryConvert(new FunctionNode('rgb', [
            new StringNode('255'),
            new NumberNode(0, ''),
            new NumberNode(0, ''),
        ]));

        expect($result)->toBeNull();
    });

    it('converts lossless byte rgb channels to short hex', function () {
        $result = $this->converter->tryConvert(new FunctionNode('rgb', [
            new NumberNode(255, ''),
            new NumberNode(0, ''),
            new NumberNode(0, ''),
        ]));

        expect($result?->value)->toBe('#f00');
    });

    it('rejects byte rgb channels that would lose precision', function () {
        $result = $this->converter->tryConvert(new FunctionNode('rgb', [
            new NumberNode(12.5, ''),
            new NumberNode(0, ''),
            new NumberNode(0, ''),
        ]));

        expect($result)->toBeNull();
    });

    it('converts lossless percentage rgb channels to hex', function () {
        $result = $this->converter->tryConvert(new FunctionNode('rgb', [
            new NumberNode(20, '%'),
            new NumberNode(40, '%'),
            new NumberNode(60, '%'),
        ]));

        expect($result?->value)->toBe('#369');
    });

    it('rejects percentage rgb channels that would lose precision', function () {
        $result = $this->converter->tryConvert(new FunctionNode('rgb', [
            new NumberNode(50, '%'),
            new NumberNode(25, '%'),
            new NumberNode(0, '%'),
        ]));

        expect($result)->toBeNull();
    });

    it('encodes rgba colors with alpha as rgba hex', function () {
        $result = $this->converter->tryConvert(new FunctionNode('rgba', [
            new NumberNode(255, ''),
            new NumberNode(0, ''),
            new NumberNode(0, ''),
            new NumberNode(0.5),
        ]));

        expect($result?->value)->toBe('#ff000080');
    });
});
