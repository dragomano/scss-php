<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassColor;

describe('SassColor', function () {
    it('six-digit hex color preserves original format', function () {
        $color = new SassColor('#FF0000');

        expect($color->toCss())->toBe('#FF0000');
    });

    it('three-digit hex is returned as-is', function () {
        $color = new SassColor('#F00');

        expect($color->toCss())->toBe('#F00');
    });

    it('named color passes through unchanged (no hash prefix)', function () {
        // normalize() returns null for non-hash values, so raw value is used
        $color = new SassColor('red');

        expect($color->toCss())->toBe('red');
    });

    it('isTruthy() always returns true', function () {
        $color = new SassColor('#000');

        expect($color->isTruthy())->toBeTrue();
    });

    it('__toString() delegates to toCss()', function () {
        $color = new SassColor('blue');

        expect((string) $color)->toBe('blue');
    });

    it('lowercase six-digit hex is returned as-is', function () {
        $color = new SassColor('#aabbcc');

        expect($color->toCss())->toBe('#aabbcc');
    });

    it('uppercase six-digit hex is returned as-is', function () {
        $color = new SassColor('#AABBCC');

        expect($color->toCss())->toBe('#AABBCC');
    });

    it('preserves rgb colors when hex output is disabled', function () {
        $color = new SassColor('rgb(255, 0, 0)');

        expect($color->toCss())->toBe('rgb(255, 0, 0)');
    });

    it('converts rgb colors to hex when compressed', function () {
        $color = new SassColor('rgb(102, 175.8, 255)', compressed: true);

        expect($color->toCss())->toBe('#66b0ff');
    });

    it('expands four-digit hex with digits to rgba', function () {
        $color = new SassColor('#0123');

        expect($color->toCss())->toBe('rgba(0, 17, 34, 0.2)');
    });

    it('expands four-digit hex with letters to rgba', function () {
        $color = new SassColor('#AbCd');

        expect($color->toCss())->toBe('rgba(170, 187, 204, 0.8666666667)');
    });

    it('expands eight-digit hex to rgba', function () {
        $color = new SassColor('#98765432');

        expect($color->toCss())->toBe('rgba(152, 118, 84, 0.1960784314)');
    });

    it('expands eight-digit hex with mixed case to rgba', function () {
        $color = new SassColor('#aBcDeF12');

        expect($color->toCss())->toBe('rgba(171, 205, 239, 0.0705882353)');
    });

    it('removes leading zero from alpha when compressed', function () {
        $color = new SassColor('#0123', compressed: true);

        expect($color->toCss())->toBe('rgba(0, 17, 34, .2)');
    });

    it('preserves four-digit hex with full alpha as rgba', function () {
        $color = new SassColor('#ff0000ff');

        expect($color->toCss())->toBe('rgba(255, 0, 0, 1)');
    });

});
