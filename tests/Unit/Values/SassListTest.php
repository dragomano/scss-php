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

    it('keeps equal items instead of collapsing box shorthand', function () {
        $list = new SassList(['4px', '4px'], 'space');

        expect($list->toCss())->toBe('4px 4px');
    });

    it('keeps four items unchanged', function () {
        $list = new SassList(['1px', '2px', '3px', '2px'], 'space');

        expect($list->toCss())->toBe('1px 2px 3px 2px');
    });

    it('keeps non-space lists unchanged', function () {
        $list = new SassList(['4px', '4px'], 'comma');

        expect($list->toCss())->toBe('4px, 4px');
    });

    it('renders bracketed list', function () {
        $list = new SassList(['1px', '2px'], 'space', true);

        expect($list->toCss())->toBe('[1px 2px]');
    });

    it('renders slash separated list', function () {
        $list = new SassList(['1px', '2px'], 'slash');

        expect($list->toCss())->toBe('1px / 2px');
    });

    it('filters null items before rendering the list', function () {
        $list = new SassList(['null', '10px', 'null', '20px'], 'space');

        expect($list->toCss())->toBe('10px 20px');
    });
});
