<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Loader;
use Tests\Support\MemoryLoader;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    describe('compileString()', function () {

        it('falls back to css output for a namespaced call with an empty member name', function () {
            $css = $this->compiler->compileString('.a { b: math.(); }');

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              b: ();
            }
            CSS);
        });

        it('emits forwarded builtins through a module prefix', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-fce-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $modulePath = $tmpDir . '/_fw.scss';

            file_put_contents($modulePath, <<<'SCSS'
            @forward "sass:string" as s-*;
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $css = $compiler->compileString(<<<'SCSS'
                @use "fw" as f;
                .a { b: f.s-index("abc", "b"); }
                SCSS);

                expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
                .a {
                  b: 2;
                }
                CSS);

                $missCompiler = new Compiler(loader: new Loader([$tmpDir]));

                $missedCss = $missCompiler->compileString(<<<'SCSS'
                @use "fw" as f;
                .a { b: f.rgb(1, 2, 3); }
                SCSS);

                expect($missedCss)->toEqualCss(/** @lang text */ <<<'CSS'
                .a {
                  b: rgb(1, 2, 3);
                }
                CSS);

                $emptyCandidateCompiler = new Compiler(loader: new Loader([$tmpDir]));

                $emptyCss = $emptyCandidateCompiler->compileString(<<<'SCSS'
                @use "fw" as f;
                .a { b: f.s-("abc"); }
                SCSS);

                expect($emptyCss)->toEqualCss(/** @lang text */ <<<'CSS'
                .a {
                  b: s-("abc");
                }
                CSS);
            } finally {
                unlink($modulePath);
                rmdir($tmpDir);
            }
        });

        it('resolves user function from captured scope', function () {
            $loader = new MemoryLoader([
                '/lib.scss' => '@function live-a($x) { @return $x * 3; }',
            ]);

            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "sass:meta" as m2;
            @use "sass:map" as map2;
            @use "lib" as lib;

            .a {
              b: m2.call(map2.get(m2.module-functions("lib"), "live-a"), 2);
            }
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              b: 6;
            }
            CSS);
        });

        it('keeps a colon inside a dynamic function name', function () {
            $css = $this->compiler->compileString(".a { b: #{'a:b'}(1); }");

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              b: a:b(1);
            }
            CSS);
        });

        it('rethrows argument evaluation failures of unknown css functions', function () {
            expect(fn() => $this->compiler->compileString('.a { b: unknown-fn($missing); }'))
                ->toThrow(UndefinedSymbolException::class);
        });

        it('serializes rgb constructor results with unresolved channels to css functions', function () {
            $cases = [
                ['.a { color: rgb(none 0 0 / 50%); }',       'color: rgb(none 0 0 / 0.5)'],
                ['.a { color: hsl(120, 50%, 50%, 0.5); }', 'color: hsla(120, 50%, 50%, 0.5)'],
                ['.a { color: rgba(env(safe) 0 0); }',       'color: rgba(env(safe), 0, 0)'],
            ];

            foreach ($cases as $case) {
                expect($this->compiler->compileString($case[0]))->toContain($case[1]);
            }
        });

        it('preserves unresolved rgb channels when the resolved color function differs from the input', function () {
            $cases = [
                ['.a { color: hsl(120, 50%, 50%, var(--a)); }', 'color: hsl(120, 50%, 50%, var(--a))'],
                ['.a { color: rgb(1 2 3 / var(--a)); }',        'color: rgb(1, 2, 3, var(--a))'],
                ['.a { color: rgba(1, 2, 3, var(--a)); }',      'color: rgba(1, 2, 3, var(--a))'],
            ];

            foreach ($cases as $case) {
                expect($this->compiler->compileString($case[0]))->toEqualCss(
                    /** @lang text */
                    ".a {\n  {$case[1]};\n}",
                );
            }
        });

        it('compares two insensitive function refs', function () {
            $css = $this->compiler->compileString(
                ".a { content: meta.get-function('quote', \$css: true) == meta.get-function('quote', \$css: true); }",
            );

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              content: true;
            }
            CSS);
        });

        it('compares a missing missing function ref against another css function ref', function () {
            $css = $this->compiler->compileString(
                ".a { content: meta.get-function('quote', \$css: true) == meta.get-function('unquote', \$css: true); }",
            );

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              content: false;
            }
            CSS);
        });

        it('preserves lab constructor results with missing channels in css output', function () {
            $cases = [
                ['.a { color: lab(50% 20 30); }',           'color: lab(50% 20 30)'],
                ['.a { color: hsl(120deg 50% 50% / none); }', 'color: hsl(120deg 50% 50% / none)'],
                ['.a { color: rgb(1 2 3 / var(--a)); }',    'color: rgb(1, 2, 3, var(--a))'],
                ['.a { color: rgb(none 1 2); }',            'color: rgb(none 1 2)'],
            ];

            foreach ($cases as $case) {
                expect($this->compiler->compileString($case[0]))->toEqualCss(
                    /** @lang text */
                    ".a {\n  {$case[1]};\n}",
                );
            }
        });

        it('serializes uppercase rgb constructors with top-level none channels as css functions', function () {
            $css = $this->compiler->compileString('.a { color: RGBA(none, 0, 0); }');

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              color: rgba(none, 0, 0);
            }
            CSS);
        });

        it('serializes uppercase rgb constructors with a non-none top-level string channel as css functions', function () {
            $css = $this->compiler->compileString('.a { color: RGBA(foo, 0, 0); }');

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              color: rgba(foo, 0, 0);
            }
            CSS);
        });

        it('serializes uppercase rgb constructors with unresolved top-level channels as css functions', function () {
            $css = $this->compiler->compileString('.a { color: RGBA(var(--r), 0, 0); }');

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              color: rgba(var(--r), 0, 0);
            }
            CSS);
        });
    });
});
