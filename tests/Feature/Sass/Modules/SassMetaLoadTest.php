<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Tests\Support\MemoryLoader;

describe('Sass Meta load Feature', function () {
    describe('meta.type-of()', function () {
        it('returns module for a loaded user module', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => '$value: c;',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            $m: meta.load("other");
            a { x: meta.type-of($m); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            a {
              x: module;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('$with configuration', function () {
        it('configures !default variables like @use ... with', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => '$a: e !default;',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            $m: meta.load("other", $with: (a: b));
            c { d: meta.inspect(meta.module-variables($m)); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            c {
              d: ("a": b);
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('live reference', function () {
        it('reflects later mutations of an already @used module', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => '$value: c;',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            $m: meta.load("other");
            a {
              before: meta.inspect(meta.module-variables($m));
              other.$value: b;
              after: meta.inspect(meta.module-variables($m));
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            a {
              before: ("value": c);
              after: ("value": b);
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('shared state', function () {
        it('runs the module side effects that mutate a shared module', function () {
            $loader = new MemoryLoader([
                '/_shared.scss' => '$b: default value;',
                '/_other.scss'  => <<<'SCSS'
                @use "shared";
                shared.$b: value set by other;
                SCSS,
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "shared";
            $_: meta.load("other");
            a { shared-b: shared.$b; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            a {
              shared-b: value set by other;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('built-in module', function () {
        it('returns a module value for a built-in module without configuration', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:color";
            $m: meta.load("sass:color");
            a { x: meta.type-of($m); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            a {
              x: module;
            }
            CSS;

            expect((new Compiler())->compileString($scss))->toEqualCss($expected);
        });

        it('throws when a built-in module is configured with $with', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            $_: meta.load("sass:color", $with: (a: b));
            SCSS;

            expect(fn() => (new Compiler())->compileString($scss))
                ->toThrow(ModuleResolutionException::class);
        });
    });

    describe('deferred CSS errors', function () {
        it('does not surface extend errors because the module CSS is discarded', function () {
            $loader = new MemoryLoader([
                '/_other.scss' => 'a {@extend missing}',
            ], '/');

            $compiler = new Compiler(loader: $loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            $_: meta.load("other");
            SCSS;

            expect($compiler->compileString($scss))->toEqualCss('');
        });
    });
});
