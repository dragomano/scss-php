<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassModule;

describe('SassModule', function () {
    it('toCss() wraps a builtin module name in get-module() call', function () {
        $module = new SassModule('meta');

        expect($module->toCss())->toBe('get-module("meta")');
    });

    it('toCss() returns get-module() without a name for user modules', function () {
        $module = new SassModule();

        expect($module->toCss())->toBe('get-module()');
    });

    it('isTruthy() always returns true', function () {
        expect((new SassModule('math'))->isTruthy())->toBeTrue()
            ->and((new SassModule())->isTruthy())->toBeTrue();
    });

    it('__toString() delegates to toCss()', function () {
        expect((string) new SassModule('color'))->toBe('get-module("color")')
            ->and((string) new SassModule())->toBe('get-module()');
    });
});
