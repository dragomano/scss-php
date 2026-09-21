<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Tests\Support\MemoryLoader;

describe('Sass Meta get-module Feature', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    describe('meta.type-of()', function () {
        it('returns module for a builtin module reference', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .type { value: meta.type-of(meta.get-module("meta")); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .type {
              value: module;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns module for a user module reference', function () {
            $loader = new MemoryLoader([
                '/_helpers.scss' => '@function noop() { @return 1; }',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "helpers" as one;
            .type { value: meta.type-of(meta.get-module("one")); }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .type {
              value: module;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.inspect()', function () {
        it('serializes a builtin module as get-module() with its name', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .inspect { value: meta.inspect(meta.get-module("meta")); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .inspect {
              value: get-module("meta");
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('serializes a user module as get-module() without a name', function () {
            $loader = new MemoryLoader([
                '/_helpers.scss' => '@function noop() { @return 1; }',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "helpers" as one;
            .inspect { value: meta.inspect(meta.get-module("one")); }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .inspect {
              value: get-module();
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('equality', function () {
        it('treats the same builtin module reference as equal', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .eq { value: meta.get-module("meta") == meta.get-module("meta"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .eq {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('treats different builtin module references as unequal', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:color";
            .eq { value: meta.get-module("meta") == meta.get-module("color"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .eq {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('treats the same user module reference as equal', function () {
            $loader = new MemoryLoader([
                '/_helpers.scss' => '@function noop() { @return 1; }',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "helpers" as one;
            .eq { value: meta.get-module("one") == meta.get-module("one"); }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .eq {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('treats different user module references as unequal', function () {
            $loader = new MemoryLoader([
                '/_first.scss'  => '@function noop() { @return 1; }',
                '/_second.scss' => '@function noop() { @return 2; }',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "first" as one;
            @use "second" as two;
            .eq { value: meta.get-module("one") == meta.get-module("two"); }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .eq {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('treats a builtin and a user module reference as unequal', function () {
            $loader = new MemoryLoader([
                '/_helpers.scss' => '@function noop() { @return 1; }',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "helpers" as one;
            .eq { value: meta.get-module("meta") == meta.get-module("one"); }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .eq {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    it('throws for an unknown module namespace', function () {
        $scss = <<<'SCSS'
        @use "sass:meta";
        .broken { value: meta.type-of(meta.get-module("does-not-exist")); }
        SCSS;

        expect(fn() => $this->compiler->compileString($scss))
            ->toThrow(ModuleResolutionException::class);
    });
});
