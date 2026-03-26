<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassBoolean;

describe('SassBoolean', function () {
    it('fromBool(true) returns truthy instance', function () {
        $bool = SassBoolean::fromBool(true);

        expect($bool->isTruthy())->toBeTrue()
            ->and($bool->toCss())->toBe('true');
    });

    it('fromBool(false) returns falsy instance', function () {
        $bool = SassBoolean::fromBool(false);

        expect($bool->isTruthy())->toBeFalse()
            ->and($bool->toCss())->toBe('false');
    });

    it('true instances are the same singleton', function () {
        $a = SassBoolean::fromBool(true);
        $b = SassBoolean::fromBool(true);

        expect($a)->toBe($b);
    });

    it('false instances are the same singleton', function () {
        $a = SassBoolean::fromBool(false);
        $b = SassBoolean::fromBool(false);

        expect($a)->toBe($b);
    });

    it('true and false instances are distinct', function () {
        expect(SassBoolean::fromBool(true))->not->toBe(SassBoolean::fromBool(false));
    });

    it('__toString() returns css representation', function () {
        expect((string) SassBoolean::fromBool(true))->toBe('true')
            ->and((string) SassBoolean::fromBool(false))->toBe('false');
    });
});
