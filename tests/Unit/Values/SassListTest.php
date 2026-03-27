<?php

declare(strict_types=1);

use Bugo\SCSS\Values\SassList;

describe(SassList::class, function () {
    it('renders space separated list', function () {
        $list = new SassList(['10px', '20px'], 'space');

        expect($list->toCss())->toBe('10px 20px');
    });

    it('omits separator when hyphenated fragments should stay adjacent', function () {
        $list = new SassList(['foo-', 'bar'], 'space');

        expect($list->toCss())->toBe('foo-bar');
    });

    it('optimizes four-value box shorthand', function () {
        $list = new SassList(['10px', '20px', '10px', '20px'], 'space');

        expect($list->toCss())->toBe('10px 20px');
    });

    it('optimizes four-value box shorthand to three values when only left and right match', function () {
        $list = new SassList(['1px', '2px', '3px', '2px'], 'space');

        expect($list->toCss())->toBe('1px 2px 3px');
    });

    it('optimizes three-value box shorthand to two values', function () {
        $list = new SassList(['3px', '6px', '3px'], 'space');

        expect($list->toCss())->toBe('3px 6px');
    });

    it('optimizes three-value box shorthand to one value', function () {
        $list = new SassList(['5px', '5px', '5px'], 'space');

        expect($list->toCss())->toBe('5px');
    });

    it('optimizes two equal box values to one value', function () {
        $list = new SassList(['4px', '4px'], 'space');

        expect($list->toCss())->toBe('4px');
    });

    it('keeps non-space lists unchanged in box optimization mode', function () {
        $list = new SassList(['4px', '4px'], 'comma');

        expect($list->toCss())->toBe('4px, 4px');
    });
})->covers(SassList::class);
