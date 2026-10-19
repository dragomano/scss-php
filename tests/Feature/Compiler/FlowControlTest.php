<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Syntax;
use Tests\Support\ArrayLogger;
use Tests\Support\MemoryLoader;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('compiles @for and @while loops', function () {
            $source = <<<'SCSS'
            @for $i from 1 through 3 {
              .item {
                order: $i;
              }
            }

            $counter: 1;
            @while $counter <= 2 {
              .counter {
                value: $counter;
              }
              $counter: $counter + 1;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .item {
              order: 1;
            }

            .item {
              order: 2;
            }

            .item {
              order: 3;
            }

            .counter {
              value: 1;
            }

            .counter {
              value: 2;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports @each with interpolated property names in mixins', function () {
            $source = <<<'SCSS'
            @mixin prefix($property, $value, $prefixes) {
              @each $prefix in $prefixes {
                -#{$prefix}-#{$property}: $value;
              }
              #{$property}: $value;
            }

            .gray {
              @include prefix(filter, grayscale(50%), moz webkit);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .gray {
              -moz-filter: grayscale(50%);
              -webkit-filter: grayscale(50%);
              filter: grayscale(50%);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('resolves variable arithmetic inside @if operands', function () {
            $source = <<<'SCSS'
            @function fit($i, $limit) {
              @if ($i > 3 and $limit >= $i * 2) {
                @return $i * 2;
              }

              @return 0;
            }

            .small {
              width: fit(4, 10);
            }

            .big {
              width: fit(4, 6);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .small {
              width: 8;
            }

            .big {
              width: 0;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('treats unicode variable names as bare references in conditions', function () {
            $source = <<<'SCSS'
            $цвет: firebrick;

            .x {
              @if $цвет {
                color: $цвет;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .x {
              color: firebrick;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('propagates !default reassignment from an @if block to the enclosing scope', function () {
            $source = <<<'SCSS'
            $map: null !default;

            @if true {
              $map: (a: 1, b: 2) !default;
            }

            .first {
              v: map-get($map, a);
            }

            .second {
              @each $key, $value in $map {
                --item-#{$key}: #{$value};
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .first {
              v: 1;
            }

            .second {
              --item-a: 1;
              --item-b: 2;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('runs no iterations when @each receives null', function () {
            $source = <<<'SCSS'
            .pair {
              count: 1;

              @each $key, $value in null {
                --#{$key}: #{$value};
              }
            }

            .single {
              count: 2;

              @each $item in null {
                --item: #{$item};
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .pair {
              count: 1;
            }

            .single {
              count: 2;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('treats only false and null as falsey in conditional directives', function () {
            $source = <<<'SCSS'
            .test {
              @if false {
                false-check: fail;
              } @else {
                false-check: ok;
              }

              @if null {
                null-check: fail;
              } @else {
                null-check: ok;
              }

              @if 0 {
                zero-check: ok;
              } @else {
                zero-check: fail;
              }

              @if "" {
                empty-string-check: ok;
              } @else {
                empty-string-check: fail;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              false-check: ok;
              null-check: ok;
              zero-check: ok;
              empty-string-check: ok;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('uses string.index return value as truthy falsey in @if', function () {
            $source = <<<'SCSS'
            @use "sass:string";

            .a {
              @if string.index("Segoe UI", " ") {
                has-space: yes;
              } @else {
                has-space: no;
              }
            }

            .b {
              @if string.index("SegoeUI", " ") {
                has-space: yes;
              } @else {
                has-space: no;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              has-space: yes;
            }

            .b {
              has-space: no;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('bubbles declarations and nested selectors from SCSS media blocks inside rules', function () {
            $source = <<<'SCSS'
            .article-container {
              @media (max-width: 767px) {
                grid-template-columns: 1fr !important;

                .featured-article,
                .article {
                  grid-column: span 1 !important;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (max-width: 767px) {
              .article-container {
                grid-template-columns: 1fr !important;
              }
              .article-container .featured-article,
              .article-container .article {
                grid-column: span 1 !important;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('bubbles declarations from SCSS nested selectors inside media queries', function () {
            $source = <<<'SCSS'
            .footer {
              @media screen and (max-width: 500px) {
                display: grid;
                gap: 4px;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media screen and (max-width: 500px) {
              .footer {
                display: grid;
                gap: 4px;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('bubbles declarations from SASS nested selectors inside media queries', function () {
            $source = <<<'SASS'
            .footer
              @media screen and (max-width: 500px)
                display: grid
                gap: 4px
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            @media screen and (max-width: 500px) {
              .footer {
                display: grid;
                gap: 4px;
              }
            }
            CSS;

            expect($this->compiler->compileString($source, Syntax::SASS))->toEqualCss($expected);
        });

        it('evaluates if(sass(...)) conditions lazily across and-operands', function () {
            $source = <<<'SCSS'
            @use "sass:meta";

            @mixin probe() {
              box-sizing: if(sass(meta.variable-exists(include-column-box-sizing) and $include-column-box-sizing): border-box; else: null);
              flex-shrink: 0;
            }

            .a { @include probe(); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              flex-shrink: 0;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('unwraps parenthesized sass(...) if conditions already resolved during evaluation', function () {
            $source = <<<'SCSS'
            $enable-feature: true;

            .a {
              width: if((sass($enable-feature)): 1px; else: 2px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              width: 1px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('unwraps sass wrappers restored by variable evaluation inside if conditions', function () {
            $source = <<<'SCSS'
            $c: (sass(true));

            .a {
              width: if(sass($c): 1px; else: 2px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              width: 1px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('picks the if(sass(...)) true branch when the guarded variable exists', function () {
            $loader = new MemoryLoader([
                '/_guard.scss' => '$include-column-box-sizing: true;',
            ]);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "sass:meta";
            @use "guard" as *;

            .a {
              box-sizing: if(sass(meta.variable-exists(include-column-box-sizing) and $include-column-box-sizing): border-box; else: null);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              box-sizing: border-box;
            }
            CSS;

            expect($compiler->compileString($source))->toEqualCss($expected);
        });
    });
});
