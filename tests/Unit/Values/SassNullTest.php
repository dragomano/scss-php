<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassNull;

describe('SassNull', function () {
    it('is a singleton', function () {
        $a = SassNull::instance();
        $b = SassNull::instance();

        expect($a)->toBe($b);
    });

    it('toCss() returns "null"', function () {
        expect(SassNull::instance()->toCss())->toBe('null');
    });

    it('isTruthy() returns false', function () {
        expect(SassNull::instance()->isTruthy())->toBeFalse();
    });

    it('__toString() returns "null"', function () {
        expect((string) SassNull::instance())->toBe('null');
    });
});
