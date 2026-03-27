<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassMap;
use Bugo\SCSS\Values\SassString;

describe('SassMap', function () {
    it('empty map produces "()"', function () {
        $map = new SassMap([]);

        expect($map->toCss())->toBe('()');
    });

    it('single-pair map renders key: value', function () {
        $map = new SassMap([
            ['key' => new SassString('color'), 'value' => new SassString('red')],
        ]);

        expect($map->toCss())->toBe('(color: red)');
    });

    it('multi-pair map renders comma-separated pairs', function () {
        $map = new SassMap([
            ['key' => new SassString('a'), 'value' => new SassString('1')],
            ['key' => new SassString('b'), 'value' => new SassString('2')],
        ]);

        expect($map->toCss())->toBe('(a: 1, b: 2)');
    });

    it('isTruthy() always returns true', function () {
        $emptyMap    = new SassMap([]);
        $nonEmptyMap = new SassMap([
            ['key' => new SassString('x'), 'value' => new SassString('y')],
        ]);

        expect($emptyMap->isTruthy())->toBeTrue()
            ->and($nonEmptyMap->isTruthy())->toBeTrue();
    });

    it('__toString() delegates to toCss()', function () {
        $map = new SassMap([
            ['key' => new SassString('k'), 'value' => new SassString('v')],
        ]);

        expect((string) $map)->toBe('(k: v)');
    });
});
