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
    });
});
