<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Syntax;
use Tests\ArrayLogger;

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
              .article-container .featured-article, .article-container .article {
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
    });
});
