<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassMixin;

describe('SassMixin', function () {
    it('name() returns the stored mixin name', function () {
        $mixin = new SassMixin('button');

        expect($mixin->name())->toBe('button');
    });

    it('toCss() wraps name in get-mixin() call', function () {
        $mixin = new SassMixin('card');

        expect($mixin->toCss())->toBe('get-mixin("card")');
    });

    it('isTruthy() always returns true', function () {
        expect((new SassMixin('any'))->isTruthy())->toBeTrue();
    });

    it('__toString() delegates to toCss()', function () {
        $mixin = new SassMixin('icon');

        expect((string) $mixin)->toBe('get-mixin("icon")');
    });
});
