<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;

describe('Compiler extended selectors edge cases', function () {
    beforeEach(function () {
        $this->compiler = new Compiler(logger: new ArrayLogger());
    });

    describe('compileString()', function () {
        it('extends complex selectors inside :is parentheses', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .x:is(.a .b) { color: red }

            .c { @extend .a; }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .x:is(.a .b, .c .b) {
              color: red;
            }
            CSS);
        });

        it('extends selectors listed inside :is parentheses', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .x:is(.a, .b) { color: red }

            .c { @extend .a; }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .x:is(.a, .c, .b) {
              color: red;
            }
            CSS);
        });

        it('nests extended selectors inside sibling :is parentheses', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .x:is(:is(.a)) { color: red }

            .c { @extend .a; }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .x:is(.a, .c) {
              color: red;
            }
            CSS);
        });

        it('nests extended selectors inside sibling :where parentheses', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .x:where(:where(.a)) { color: red }

            .c { @extend .a; }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .x:where(.a, .c) {
              color: red;
            }
            CSS);
        });

        it('nests extended selectors inside sibling :matches parentheses', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .x:matches(:matches(.a)) { color: red }

            .c { @extend .a; }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .x:matches(.a, .c) {
              color: red;
            }
            CSS);
        });

        it('keeps chained :not pseudos after extending inner selectors', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .x:is(:not(.a)) { color: red }

            .c { @extend .a; }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .x:is(:not(.a):not(.c)) {
              color: red;
            }
            CSS);
        });

        it('strips vendor prefixes from pseudo extension names', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            *:-moz-any(.a) { color: red }

            .c { @extend .a; }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            *:-moz-any(.a, .c) {
              color: red;
            }
            CSS);
        });

        it('nests selectors after extends while preserving pseudo element chains', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            a { color: red }

            b {
              @extend a;

              a .c:hover { margin: 0; }
            }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a, b {
              color: red;
            }

            b a .c:hover, b b .c:hover {
              margin: 0;
            }
            CSS);
        });

        it('collects extends for each loop iterations nested inside rules', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            [data-el] {
              @each $x in a b {
                .y-#{$x} { @extend [data-el]; margin: 0; }
              }
            }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            [data-el] .y-a {
              margin: 0;
            }
            [data-el] .y-b {
              margin: 0;
            }
            CSS);
        });

        it('collects extends from while loop iterations inside rules', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .x {
              $i: 0;

              @while $i < 2 {
                .y-#{$i} { @extend .x; margin: 0; }

                $i: $i + 1;
              }
            }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .x .y-0 {
              margin: 0;
            }
            .x .y-1 {
              margin: 0;
            }
            CSS);
        });

        it('normalizes color function arguments into css serialization output', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            a {
              color: rgb(none, 50%, 25%);
              border-color: rgb(var(--x), 1, 2);
              outline-color: hsl(0, 100%, 50%);
            }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              color: rgb(none, 50%, 25%);
              border-color: rgb(var(--x), 1, 2);
              outline-color: hsl(0, 100%, 50%);
            }
            CSS);
        });

        it('keeps equal pseudo element selectors without collapsing duplicates', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            a::before, a { color: red }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a::before, a {
              color: red;
            }
            CSS);
        });

        it('keeps universal selector pairs without collapsing equivalent colors', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            a *, * { color: red }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a *, * {
              color: red;
            }
            CSS);
        });

        it('keeps reorder-equivalent compound selectors on the output', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            .a.b, .b.a { color: red }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            .a.b, .b.a {
              color: red;
            }
            CSS);
        });

        it('keeps descendant selector chains intact', function () {
            $css = $this->compiler->compileString(<<<'SCSS'
            a, .a .b { color: red }
            SCSS);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a, .a .b {
              color: red;
            }
            CSS);
        });
    });
});
