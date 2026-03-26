<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;

describe('CompilerOptions', function () {
    it('has default values', function () {
        $options = new CompilerOptions();

        expect($options->style)->toBe(Style::EXPANDED)
            ->and($options->sourceFile)->toBe('input.scss')
            ->and($options->outputFile)->toBe('output.css')
            ->and($options->sourceMapFile)->toBeNull()
            ->and($options->includeSources)->toBeFalse()
            ->and($options->splitRules)->toBeFalse()
            ->and($options->verboseLogging)->toBeFalse();
    });

    it('accepts custom style', function () {
        $options = new CompilerOptions(style: Style::COMPRESSED);

        expect($options->style)->toBe(Style::COMPRESSED);
    });

    it('accepts custom source file', function () {
        $options = new CompilerOptions(sourceFile: 'custom.scss');

        expect($options->sourceFile)->toBe('custom.scss');
    });

    it('accepts source map file', function () {
        $options = new CompilerOptions(sourceMapFile: 'output.css.map');

        expect($options->sourceMapFile)->toBe('output.css.map');
    });

    it('accepts includeSources flag', function () {
        $options = new CompilerOptions(includeSources: true);

        expect($options->includeSources)->toBeTrue();
    });

    it('accepts verboseLogging flag', function () {
        $options = new CompilerOptions(verboseLogging: true);

        expect($options->verboseLogging)->toBeTrue();
    });

    it('accepts splitRules flag', function () {
        $options = new CompilerOptions(splitRules: true);

        expect($options->splitRules)->toBeTrue();
    });
});
