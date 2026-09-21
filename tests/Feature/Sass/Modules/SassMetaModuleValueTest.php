<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\InvalidArgumentTypeException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Tests\Support\MemoryLoader;

describe('Sass Meta module-value argument Feature', function () {
    beforeEach(function () {
        // A user module exposing a function, a mixin and variables (one of them null).
        $this->loader = new MemoryLoader([
            '/_other.scss' => <<<'SCSS'
            $c: null;
            $size: 10px;
            @function my-fn($x) { @return $x + 1; }
            @mixin c() { color: red; }
            SCSS,
        ], '/');
    });

    describe('meta.function-exists', function () {
        it('accepts a builtin module value', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:color";
            .a {
              yes: meta.function-exists("red", meta.get-module("color"));
              no: meta.function-exists("c", meta.get-module("color"));
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              yes: true;
              no: false;
            }
            CSS;

            expect((new Compiler())->compileString($scss))->toEqualCss($expected);
        });

        it('accepts a user module value', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a {
              yes: meta.function-exists("my-fn", meta.get-module("other"));
              no: meta.function-exists("nope", meta.get-module("other"));
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              yes: true;
              no: false;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('meta.get-function with a module value', function () {
        it('resolves a builtin function and calls it via meta.call', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:math";
            .a { value: meta.call(meta.get-function("round", $module: meta.get-module("math")), 0.6); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: 1;
            }
            CSS;

            expect((new Compiler())->compileString($scss))->toEqualCss($expected);
        });

        it('resolves a user function and calls it via meta.call', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a { value: meta.call(meta.get-function("my-fn", $module: meta.get-module("other")), 4); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: 5;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });

        it('throws when the function is missing from the module value', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a { value: meta.get-function("nope", $module: meta.get-module("other")); }
            SCSS;

            expect(fn() => $compiler->compileString($scss))
                ->toThrow(ModuleResolutionException::class);
        });
    });

    describe('meta.get-mixin with a module value', function () {
        it('resolves a user mixin and includes it via meta.apply', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            b { @include meta.apply(meta.get-mixin("c", $module: meta.get-module("other"))); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            b {
              color: red;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });

        it('throws when the mixin is missing from the module value', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            b { @include meta.apply(meta.get-mixin("nope", $module: meta.get-module("other"))); }
            SCSS;

            expect(fn() => $compiler->compileString($scss))
                ->toThrow(ModuleResolutionException::class);
        });
    });

    describe('meta.global-variable-exists with a module value', function () {
        it('returns false for a builtin module without the variable', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:color";
            .a { value: meta.global-variable-exists("c", meta.get-module("color")); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: false;
            }
            CSS;

            expect((new Compiler())->compileString($scss))->toEqualCss($expected);
        });

        it('returns true for a null-valued user module variable', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a { value: meta.global-variable-exists("c", meta.get-module("other")); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: true;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('meta.variable-exists with a module value', function () {
        it('returns true for a null-valued user module variable', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a { value: meta.variable-exists("c", meta.get-module("other")); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: true;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('meta.mixin-exists with a module value', function () {
        it('distinguishes user and builtin module values', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:color";
            @use "other";
            .a {
              user: meta.mixin-exists("c", meta.get-module("other"));
              builtin: meta.mixin-exists("c", meta.get-module("color"));
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              user: true;
              builtin: false;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('meta.module-variables with a module value', function () {
        it('returns the variables of a user module', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a { value: meta.inspect(meta.module-variables(meta.get-module("other"))); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: ("c": null, "size": 10px);
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('meta.module-functions with a module value', function () {
        it('returns a map for a builtin module value', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:math";
            .a { value: meta.type-of(meta.module-functions(meta.get-module("math"))); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: map;
            }
            CSS;

            expect((new Compiler())->compileString($scss))->toEqualCss($expected);
        });

        it('returns a callable map for a user module value', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a { value: meta.call(map-get(meta.module-functions(meta.get-module("other")), "my-fn"), 7); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: 8;
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('meta.module-mixins with a module value', function () {
        it('returns a map for a user module value', function () {
            $compiler = new Compiler(loader: $this->loader);

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "other";
            .a { value: meta.inspect(map-keys(meta.module-mixins(meta.get-module("other")))); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: ("c",);
            }
            CSS;

            expect($compiler->compileString($scss))->toEqualCss($expected);
        });

        it('returns a map for a builtin meta module value', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .a { value: meta.type-of(meta.module-mixins(meta.get-module("meta"))); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: map;
            }
            CSS;

            expect((new Compiler())->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('missing required module argument', function () {
        it('throws for module export functions called without a module', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .a { value: meta.inspect(meta.module-functions()); }
            SCSS;

            expect(fn() => (new Compiler())->compileString($scss))
                ->toThrow(MissingFunctionArgumentsException::class);
        });
    });

    describe('invalid module argument', function () {
        it('throws when the module argument is neither a string nor a module value', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:color";
            .a { value: meta.function-exists("red", 1); }
            SCSS;

            expect(fn() => (new Compiler())->compileString($scss))
                ->toThrow(InvalidArgumentTypeException::class);
        });
    });
});
