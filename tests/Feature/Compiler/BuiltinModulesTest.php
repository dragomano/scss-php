<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Loader;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

        it('evaluates namespaced sass:map functions', function () {
            $source = <<<'SCSS'
            @use "sass:map";
            $m: (a: 1, b: 2);
            .test {
              value: map.get($m, b);
              has: map.has-key($m, c);
              keys: map.keys($m);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: 2;
              has: false;
              keys: a, b;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates global sass:map aliases', function () {
            $source = <<<'SCSS'
            $m: (a: 1, b: 2);
            .test {
              value: map-get($m, a);
              has: map-has-key($m, b);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: 1;
              has: true;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates namespaced sass:string functions', function () {
            $source = <<<'SCSS'
            @use "sass:string";
            .test {
              len: string.length(hello);
              inserted: string.insert(abcd, X, 3);
              sliced: string.slice(abcdef, 2, 4);
              sliced-zero: string.slice(abcdef, 0, 2);
              upper: string.to-upper-case(ab);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              len: 5;
              inserted: abXcd;
              sliced: bcd;
              sliced-zero: ab;
              upper: AB;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates global sass:string aliases', function () {
            $source = <<<'SCSS'
            .test {
              len: str-length(hello);
              idx: str-index(hello, ll);
              lower: to-lower-case(AB);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              len: 5;
              idx: 3;
              lower: ab;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates namespaced sass:math functions', function () {
            $source = <<<'SCSS'
            @use "sass:math";
            .test {
              pow: math.pow(2, 3);
              sqrt: math.sqrt(9);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              pow: 8;
              sqrt: 3;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates global sass:math aliases', function () {
            $source = <<<'SCSS'
            .test {
              rounded: round(1.8px);
              unitless: unitless(10);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              rounded: 2px;
              unitless: true;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates sass:math module variables', function () {
            $source = <<<'SCSS'
            @use "sass:math";
            .test {
              epsilon-unitless: math.is-unitless(math.$epsilon);
              safe: math.$max-safe-integer;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              epsilon-unitless: true;
              safe: 9007199254740991;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('ignores built-in sass modules in @use', function () {
            $source = <<<'SCSS'
            @use "sass:color";
            .test { color: red; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('imports variables mixins and functions into current scope for wildcard namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_configurable.scss" as *;

            .box {
              @include theme();
              tone: tone();
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .box {
              color: red;
              margin: 8px;
              tone: red;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('imports module variables into current scope for wildcard namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_theme.scss" as *;

            .box {
              color: $primary-color;
              font-size: $font-size;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .box {
              color: #007bff;
              font-size: 16px;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('throws when accessing private module variables via namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_private-module.scss" as priv;

            .box {
              value: priv.$-hidden;
            }
            SCSS;

            expect(fn() => $compiler->compileString($source))
                ->toThrow(
                    UndefinedSymbolException::class,
                    "Undefined variable \$-hidden in module 'priv'.",
                );
        });

        it('does not import private variables into scope for wildcard namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_private-module.scss" as *;

            .box {
              visible: $public;
              private: $-hidden;
            }
            SCSS;

            expect(fn() => $compiler->compileString($source))
                ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $-hidden');
        });

        it('compiles mixin from .sass module via @use', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $modulePath = $tmpDir . '/_mixins.sass';

            file_put_contents($modulePath, <<<'SASS'
            =highlight($color)
              border: 1px solid $color
            SASS);

            try {
                $loader   = new Loader([$tmpDir]);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @use "mixins";
                .test { @include mixins.highlight(blue); }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .test {
                  border: 1px solid blue;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($modulePath)) {
                    unlink($modulePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('resolves directory _index.scss for @use', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-index-use-' . uniqid('', true);
            $moduleDir = $tmpDir . '/foundation';
            mkdir($moduleDir, 0777, true);

            $indexPath = $moduleDir . '/_index.scss';
            file_put_contents($indexPath, <<<'SCSS'
            .from-index {
              color: red;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "foundation";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-index {
                  color: red;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($indexPath)) {
                    unlink($indexPath);
                }

                if (is_dir($moduleDir)) {
                    rmdir($moduleDir);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('resolves relative @use from directory _index.scss', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-index-relative-' . uniqid('', true);
            $moduleDir = $tmpDir . '/foundation';
            mkdir($moduleDir, 0777, true);

            $indexPath = $moduleDir . '/_index.scss';
            $codePath  = $moduleDir . '/_code.scss';

            file_put_contents($indexPath, <<<'SCSS'
            @use "code";
            SCSS);

            file_put_contents($codePath, <<<'SCSS'
            .from-code {
              color: red;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "foundation";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-code {
                  color: red;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($indexPath)) {
                    unlink($indexPath);
                }

                if (file_exists($codePath)) {
                    unlink($codePath);
                }

                if (is_dir($moduleDir)) {
                    rmdir($moduleDir);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });
    });
});
