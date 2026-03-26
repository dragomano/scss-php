<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassFunctionRef;

describe('SassFunctionRef', function () {
    it('name() returns the stored function name', function () {
        $ref = new SassFunctionRef('lighten');

        expect($ref->name())->toBe('lighten');
    });

    it('toCss() wraps name in get-function() call', function () {
        $ref = new SassFunctionRef('darken');

        expect($ref->toCss())->toBe('get-function("darken")');
    });

    it('isTruthy() always returns true', function () {
        expect((new SassFunctionRef('any'))->isTruthy())->toBeTrue();
    });

    it('__toString() delegates to toCss()', function () {
        $ref = new SassFunctionRef('lighten');

        expect((string) $ref)->toBe('get-function("lighten")');
    });
});
