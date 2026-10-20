<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    describe('@keyframes', function () {
        it('joins keyframe selector lists on one line', function (string $stops, string $expectedStops, string $directive) {
            $source = <<<SCSS
            @$directive blink {
              $stops { opacity: 1; }
            }
            SCSS;

            $expected = /** @lang text */ <<<CSS
            @$directive blink {
              $expectedStops {
                opacity: 1;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        })->with([
            'inline'               => ['0%, 100%', '0%, 100%'],
            'newline after comma'  => ["0%,\n100%", '0%, 100%'],
            'newline before comma' => ["0%\n,100%", '0%, 100%'],
            'mixed stops'          => ["from,\n50%, to", 'from, 50%, to'],
            'scientific notation'  => ["1E+1%,\n100%", '1e+1%, 100%'],
            'interpolation'        => ["#{0%},\n#{100%}", '0%, 100%'],
        ])->with(['keyframes', '-webkit-keyframes']);

        it('joins keyframe selector lists inside cascade layers and nested style rules', function () {
            $source = <<<'SCSS'
            @layer utilities {
              .a {
                @-webkit-keyframes blink {
                  0%,
                  100% { opacity: 1; }
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @layer utilities {
              @-webkit-keyframes blink {
                0%, 100% {
                  opacity: 1;
                }
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps keyframe selector lists compact in compressed output', function () {
            $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

            $source = <<<'SCSS'
            @keyframes blink {
              0%,
              100% { opacity: 1; }
            }
            SCSS;

            expect($compiler->compileString($source))->toEqualCss('@keyframes blink{0%,100%{opacity:1}}');
        });

        it('preserves multiline style selectors after keyframes', function () {
            $source = <<<'SCSS'
            @keyframes blink {
              0%,
              100% { opacity: 1; }
            }
            .a,
            .b { opacity: 0; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @keyframes blink {
              0%, 100% {
                opacity: 1;
              }
            }
            .a,
            .b {
              opacity: 0;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps percentage keyframe selectors', function () {
            $source = <<<'SCSS'
            @keyframes b {
              0% { c: d }
              100% { c: e }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @keyframes b {
              0% {
                c: d;
              }
              100% {
                c: e;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('does not apply @extend to keyframe selectors', function () {
            $source = <<<'SCSS'
            %placeholder { x: y }

            @keyframes b {
              0% { c: d }
            }

            .a { @extend %placeholder; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              x: y;
            }

            @keyframes b {
              0% {
                c: d;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps keyframe selectors with scientific notation', function () {
            $source = <<<'SCSS'
            @keyframes b {
              13E+1% { c: d }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @keyframes b {
              13e+1% {
                c: d;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps a bubbling at-rule inside a keyframe block', function () {
            $expected = /** @lang text */ <<<'CSS'
            @keyframes k {
              0% {
                @media screen {
                  b: c;
                }
              }
            }
            CSS;

            $css = $this->compiler->compileString('@keyframes k { 0% { @media screen { b: c } } }');

            expect($css)->toEqualCss($expected);
        });
    });

    describe('at-rule bubbling', function () {
        it('bubbles an unknown at-rule with a declaration child out of a style rule', function () {
            $expected = /** @lang text */ <<<'CSS'
            @b {
              a {
                c: d;
              }
            }
            CSS;

            expect($this->compiler->compileString('a { @b { c: d } }'))->toEqualCss($expected);
        });

        it('bubbles an unknown at-rule with a rule child out of a style rule', function () {
            $expected = /** @lang text */ <<<'CSS'
            @b {
              a c {
                d: e;
              }
            }
            CSS;

            expect($this->compiler->compileString('a { @b { c { d: e } } }'))->toEqualCss($expected);
        });

        it('keeps a childless at-rule in place', function () {
            $expected = /** @lang text */ <<<'CSS'
            a {
              @b c;
            }
            CSS;

            expect($this->compiler->compileString('a { @b c; }'))->toEqualCss($expected);
        });

        it('bubbles @font-face without copying the parent selector', function () {
            $expected = /** @lang text */ <<<'CSS'
            @font-face {
              c: d;
            }
            CSS;

            expect($this->compiler->compileString('a { @font-face { c: d } }'))->toEqualCss($expected);
        });

        it('bubbles @keyframes without copying the parent selector', function () {
            $expected = /** @lang text */ <<<'CSS'
            @keyframes b {
              0% {
                c: d;
              }
            }
            CSS;

            expect($this->compiler->compileString('a { @keyframes b { 0% { c: d } } }'))->toEqualCss($expected);
        });

        it('bubbles @supports out of a style rule nested in @media', function () {
            $expected = /** @lang text */ <<<'CSS'
            @media screen {
              @supports (x: y) {
                a {
                  b: c;
                }
              }
            }
            CSS;

            $css = $this->compiler->compileString('@media screen { a { @supports (x: y) { b: c } } }');

            expect($css)->toEqualCss($expected);
        });

        it('bubbles an unknown at-rule out of a style rule nested in @media', function () {
            $expected = /** @lang text */ <<<'CSS'
            @media screen {
              @foo {
                a {
                  b: c;
                }
              }
            }
            CSS;

            $css = $this->compiler->compileString('@media screen { a { @foo { b: c } } }');

            expect($css)->toEqualCss($expected);
        });
    });

    describe('@media feature values', function () {
        it('evaluates function calls and arithmetic in feature operands', function () {
            $source = <<<'SCSS'
            @function maxv() { @return 575.98px; }
            $w: 768px;

            @media (max-width: maxv()) { a { b: c } }
            @media (max-width: $w - 20) { a { b: c } }
            @media (device-aspect-ratio: 16/9) { a { b: c } }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (max-width: 575.98px) {
              a {
                b: c;
              }
            }
            @media (max-width: 748px) {
              a {
                b: c;
              }
            }
            @media (device-aspect-ratio: 16/9) {
              a {
                b: c;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps legacy if() branches evaluating as sass truthiness inside media', function () {
            $source = <<<'SCSS'
            $grid-breakpoints: (xs: 0, sm: 576px, md: 768px);

            @function breakpoint-next($name, $breakpoints: $grid-breakpoints, $names: map-keys($breakpoints)) {
              $n: index($names, $name);
              @return if($n != null and $n < length($names), nth($names, $n + 1), null);
            }

            @function breakpoint-max($name, $breakpoints: $grid-breakpoints) {
              $next: breakpoint-next($name, $breakpoints);
              @return if($next, map-get($breakpoints, $next) - .02, null);
            }

            @mixin media-breakpoint-down($name, $breakpoints: $grid-breakpoints) {
              $max: breakpoint-max($name, $breakpoints);

              @if $max {
                @media (max-width: $max) {
                  @content;
                }
              }
            }

            @each $breakpoint in map-keys($grid-breakpoints) {
              .resp-#{$breakpoint} {
                @include media-breakpoint-down($breakpoint) {
                  display: block;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (max-width: 575.98px) {
              .resp-xs {
                display: block;
              }
            }
            @media (max-width: 767.98px) {
              .resp-sm {
                display: block;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });
    });
});
