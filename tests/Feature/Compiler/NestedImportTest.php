<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Tests\Support\ArrayLogger;
use Tests\Support\MemoryLoader;

function compileNestedImport(string $source, array $files): string
{
    $compiler = new Compiler(
        loader: new MemoryLoader($files, '/'),
        logger: new ArrayLogger(),
    );

    return $compiler->compileString($source);
}

describe('Compiler', function () {
    describe('nested @import', function () {
        it('exposes the surrounding variable scope to the imported file', function () {
            $css = compileNestedImport('.parent { $var: value; @import "other"; }', [
                '/other.scss' => 'x { var: $var }',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .parent x {
              var: value;
            }
            CSS);
        });

        it('exposes the surrounding mixin scope to the imported file', function () {
            $css = compileNestedImport('.parent { @mixin local { x { y: z } } @import "other"; }', [
                '/other.scss' => '@include local;',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .parent x {
              y: z;
            }
            CSS);
        });

        it('exposes the surrounding function scope to the imported file', function () {
            $css = compileNestedImport('.parent { @function local() { @return value } @import "other"; }', [
                '/other.scss' => 'x { function: local() }',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .parent x {
              function: value;
            }
            CSS);
        });

        it('resolves the parent selector inside the imported file', function () {
            $css = compileNestedImport('.a { @import "upstream"; }', [
                '/_upstream.scss' => '& { b: c }',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              b: c;
            }
            CSS);
        });

        it('inlines a top-level @include from the imported file', function () {
            $css = compileNestedImport('.a { @import "upstream"; }', [
                '/_upstream.scss' => '@mixin b { c: d } @include b;',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a {
              c: d;
            }
            CSS);
        });

        it('lifts imported @keyframes above the parent selector', function () {
            $css = compileNestedImport('a { @import "other" }', [
                '/_other.scss' => '@keyframes b { 0% { c: d } }',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            @keyframes b {
              0% {
                c: d;
              }
            }
            CSS);
        });

        it('keeps an imported childless at-rule inside the parent selector', function () {
            $css = compileNestedImport('a { @import "other" }', [
                '/_other.scss' => '@b c;',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              @b c;
            }
            CSS);
        });

        it('bubbles an imported at-rule with a declaration child', function () {
            $css = compileNestedImport('a { @import "other" }', [
                '/_other.scss' => '@b { c: d }',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            @b {
              a {
                c: d;
              }
            }
            CSS);
        });

        it('bubbles an imported at-rule with a rule child', function () {
            $css = compileNestedImport('a { @import "other" }', [
                '/_other.scss' => '@b { c { d: e } }',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            @b {
              a c {
                d: e;
              }
            }
            CSS);
        });

        it('inlines every url of a multi-url import', function () {
            $css = compileNestedImport('a { @import "b", "c"; }', [
                '/_b.scss' => 'x { y: z }',
                '/_c.scss' => 'p { q: r }',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a x {
              y: z;
            }
            a p {
              q: r;
            }
            CSS);
        });

        it('separates inlined declarations coming from different urls of one import', function () {
            $css = compileNestedImport('a { @import "b", "c"; }', [
                '/_b.scss' => '@mixin m1 { c: d } @include m1;',
                '/_c.scss' => '@mixin m2 { e: f } @include m2;',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              c: d;
              e: f;
            }
            CSS);
        });

        it('inlines the same transitively imported file once per importer', function () {
            $css = compileNestedImport('@import "b"; @import "c";', [
                '/_a.scss' => '/* Y */',
                '/_b.scss' => '@import "a";',
                '/_c.scss' => '@import "a";',
            ]);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            /* Y */
            /* Y */
            CSS);
        });

        it('rejects a nested import cycle', function () {
            expect(fn() => compileNestedImport('.a { @import "b"; }', [
                '/_b.scss' => '.c { @import "b"; }',
            ]))->toThrow(ModuleResolutionException::class);
        });
    });
});
