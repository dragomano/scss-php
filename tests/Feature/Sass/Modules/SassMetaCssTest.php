<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\MemoryLoader;

describe('Sass Meta css Feature', function () {
    describe('direct module reference', function () {
        it('emits the CSS of a module loaded via meta.load()', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => 'a {b: c}',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @include meta.css(meta.load("other"));
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            a {
              b: c;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });

        it('emits the CSS twice for an @used module referenced via meta.get-module()', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => 'a {b: c}',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            @include meta.css(meta.get-module("other"));
            SCSS;

            // The module CSS is emitted once by @use and once by meta.css().
            $expected = /** @lang text */ <<<'CSS'
            a {
              b: c;
            }

            a {
              b: c;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('string module reference', function () {
        it('emits the CSS twice for an @used module referenced by name', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => 'a {b: c}',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            @include meta.css("other");
            SCSS;

            // The module CSS is emitted once by @use and once by meta.css().
            $expected = /** @lang text */ <<<'CSS'
            a {
              b: c;
            }

            a {
              b: c;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('nested include', function () {
        it('nests the module CSS under the parent selector', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => 'b {c: d}',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            a {@include meta.css(meta.load("other"))}
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            a b {
              c: d;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('named argument', function () {
        it('accepts the module through the $module named argument', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => 'a {b: c}',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @include meta.css($module: meta.load("other"));
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            a {
              b: c;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });
});
