<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\OutputRenderer;

describe('OutputRenderer', function () {
    it('has default indent cache with empty indent for level 0', function () {
        $renderer = new OutputRenderer();

        expect($renderer->indentCache)->toBe([0 => '']);
    });

    it('has default newline separator', function () {
        $renderer = new OutputRenderer();

        expect($renderer->separator)->toBe("\n");
    });

    it('accepts custom separator', function () {
        $renderer = new OutputRenderer(separator: '');

        expect($renderer->separator)->toBe('');
    });

    it('accepts custom indent cache', function () {
        $renderer = new OutputRenderer(indentCache: [0 => '', 1 => '  ']);

        expect($renderer->indentCache[1])->toBe('  ');
    });
});
