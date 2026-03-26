<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassColor;

describe('SassColor', function () {
    it('six-digit hex color is normalized and shortened when possible', function () {
        $color = new SassColor('#FF0000');

        expect($color->toCss())->toBe('#f00');
    });

    it('three-digit hex is returned lowercased', function () {
        $color = new SassColor('#F00');

        expect($color->toCss())->toBe('#f00');
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

    it('lowercase six-digit hex is shortened when possible', function () {
        $color = new SassColor('#aabbcc');

        expect($color->toCss())->toBe('#abc');
    });

    it('uppercase six-digit hex is lowercased and shortened when possible', function () {
        $color = new SassColor('#AABBCC');

        expect($color->toCss())->toBe('#abc');
    });

    it('preserves rgb colors when hex output is disabled', function () {
        $color = new SassColor('rgb(255, 0, 0)');

        expect($color->toCss())->toBe('rgb(255, 0, 0)');
    });

    it('converts rgb colors to hex when hex output is enabled', function () {
        $color = new SassColor('rgb(102, 175.8, 255)', true);

        expect($color->toCss())->toBe('#66b0ff');
    });

});
