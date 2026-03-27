<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassString;

describe('SassString', function () {
    it('unquoted string returns raw value from toCss()', function () {
        $string = new SassString('hello');

        expect($string->toCss())->toBe('hello');
    });

    it('quoted string wraps value in double quotes', function () {
        $string = new SassString('hello', quoted: true);

        expect($string->toCss())->toBe('"hello"');
    });

    it('__toString() delegates to toCss()', function () {
        $string = new SassString('world');

        expect((string) $string)->toBe('world');
    });

    it('isTruthy() always returns true', function () {
        expect((new SassString(''))->isTruthy())->toBeTrue()
            ->and((new SassString('any value'))->isTruthy())->toBeTrue()
            ->and((new SassString('', quoted: true))->isTruthy())->toBeTrue();
    });

    it('empty unquoted string returns empty string', function () {
        $string = new SassString('');

        expect($string->toCss())->toBe('');
    });

    it('empty quoted string returns empty double-quoted string', function () {
        $string = new SassString('', quoted: true);

        expect($string->toCss())->toBe('""');
    });

    it('escapes inner double quotes when double quotes are used', function () {
        $string = new SassString('say "hi" and it\'s done', quoted: true);

        expect($string->toCss())->toBe('"say \\"hi\\" and it\'s done"');
    });
});
