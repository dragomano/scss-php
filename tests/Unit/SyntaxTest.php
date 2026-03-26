<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\InvalidSyntaxException;
use Bugo\SCSS\Syntax;

describe('Syntax enum', function () {
    it('has SASS constant with correct value', function () {
        expect(Syntax::SASS->value)->toBe('sass');
    });

    it('has SCSS constant with correct value', function () {
        expect(Syntax::SCSS->value)->toBe('scss');
    });

    describe('fromPath method', function () {
        it('detects CSS syntax from file with path and .css extension', function () {
            $syntax = Syntax::fromPath('/path/to/styles.css');
            expect($syntax)->toBe(Syntax::CSS);
        });

        it('detects SASS syntax from .sass extension', function () {
            $syntax = Syntax::fromPath('styles.sass');
            expect($syntax)->toBe(Syntax::SASS);
        });

        it('detects SASS syntax from .sass file path (uppercase)', function () {
            $syntax = Syntax::fromPath('STYLES.SASS');
            expect($syntax)->toBe(Syntax::SASS);
        });

        it('detects SCSS syntax from .scss extension', function () {
            $syntax = Syntax::fromPath('styles.scss');
            expect($syntax)->toBe(Syntax::SCSS);
        });

        it('detects SCSS syntax from .scss file path (uppercase)', function () {
            $syntax = Syntax::fromPath('STYLES.SCSS');
            expect($syntax)->toBe(Syntax::SCSS);
        });

        it('detects SCSS syntax from file without extension', function () {
            $syntax = Syntax::fromPath('styles');
            expect($syntax)->toBe(Syntax::SCSS);
        });

        it('detects SCSS syntax from file with path and .scss extension', function () {
            $syntax = Syntax::fromPath('/path/to/styles.scss');
            expect($syntax)->toBe(Syntax::SCSS);
        });

        it('detects SASS syntax from file with path and .sass extension', function () {
            $syntax = Syntax::fromPath('/path/to/styles.sass');
            expect($syntax)->toBe(Syntax::SASS);
        });

        it('throws exception for unsupported extensions', function () {
            expect(fn() => Syntax::fromPath('styles.txt'))
                ->toThrow(InvalidSyntaxException::class);
        });

        it('handles paths with multiple dots correctly', function () {
            $syntax = Syntax::fromPath('my.styles.sass');
            expect($syntax)->toBe(Syntax::SASS);
        });

        describe('fromPath with content detection', function () {
            it('detects SCSS syntax from content containing curly braces', function () {
                $content = '.class { color: red; }';
                $syntax = Syntax::fromPath('styles', $content);
                expect($syntax)->toBe(Syntax::SCSS);
            });

            it('detects SASS syntax from content without curly braces', function () {
                $content = '.class\n  color: red';
                $syntax = Syntax::fromPath('styles', $content);
                expect($syntax)->toBe(Syntax::SASS);
            });
        });
    });
});
