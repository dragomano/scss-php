<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Loader;
use Bugo\SCSS\Style;
use Tests\Support\ArrayLogger;

describe('Output Formatting', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    it('compiles empty SCSS input to empty string', function () {
        $css = $this->compiler->compileString('');

        expect($css)->toBe('');
    });

    it('shortens 6-char hex literals in compressed style', function () {
        $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

        $css = $compiler->compileString('.a { color: #ff0000; background: #003366; }');

        expect($css)->toBe('.a{color:#f00;background:#036}');
    });

    it('shortens 8-char hex literals with alpha in compressed style when all pairs match', function () {
        $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

        $css = $compiler->compileString('.a { color: #ff000080; border-color: #aabbccdd; }');

        expect($css)->toBe('.a{color:rgba(255,0,0,.5019607843);border-color:rgba(170,187,204,.8666666667)}');
    });

    it('does not shorten hex literals in expanded style', function () {
        $css = $this->compiler->compileString('.a { color: #ff0000; }');

        $expected = /** @lang text */ <<<'CSS'
        .a {
          color: #ff0000;
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('does not shorten hex inside strings in compressed style', function () {
        $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

        $css = $compiler->compileString('.a { content: "#ff0000"; }');

        expect($css)->toBe('.a{content:"#ff0000"}');
    });

    it('converts functional notation for oklch results to hex in compressed style', function () {
        $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

        $scss = <<<'SCSS'
        @use "sass:color";
        $venus: #998099;
        .color-oklch {
          scale: color.scale($venus, $lightness: +15%, $space: oklch);
          mix: color.mix($venus, midnightblue, $method: oklch);
        }
        SCSS;

        $css = $compiler->compileString($scss);

        expect($css)->toBe('.color-oklch{scale:#aa91aa;mix:#604b85}');
    });

    it('converts fractional rgb functions to hex in compressed style', function () {
        $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

        $scss = <<<'SCSS'
        @use "sass:color";
        .a {
          mix: color.mix(#036, #d2e1dd, $method: rgb);
          scale: color.scale(#000, $red: 50%);
          invert: color.invert(#550e0c, 20%, $space: display-p3);
        }
        SCSS;

        $css = $compiler->compileString($scss);

        expect($css)->toBe('.a{mix:#698aa2;scale:#800000;invert:#673d3b}');
    });

    it('passes through css relative color functions', function () {
        $scss = <<<'SCSS'
        body {
          rgb: rgb(from currentcolor r g b);
          rgba: rgba(from currentcolor r g b / alpha);
          hsl: hsl(from currentcolor h s l);
          hsla: hsla(from currentcolor h s l / alpha);
          hwb: hwb(from currentcolor h w b);
          lab: lab(from currentcolor l a b);
          lch: lch(from currentcolor l c h);
          oklab: oklab(from currentcolor l a b);
          oklch: oklch(from currentcolor l c h);
          color: color(from currentcolor srgb r g b / alpha);
        }
        SCSS;

        $css = $this->compiler->compileString($scss);

        $expected = /** @lang text */ <<<'CSS'
        body {
          rgb: rgb(from currentcolor r g b);
          rgba: rgba(from currentcolor r g b/alpha);
          hsl: hsl(from currentcolor h s l);
          hsla: hsla(from currentcolor h s l/alpha);
          hwb: hwb(from currentcolor h w b);
          lab: lab(from currentcolor l a b);
          lch: lch(from currentcolor l c h);
          oklab: oklab(from currentcolor l a b);
          oklch: oklch(from currentcolor l c h);
          color: color(from currentcolor srgb r g b/alpha);
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('keeps included declarations, nested rules and extends in source order without empty blocks', function () {
        $scss = <<<'SCSS'
        @mixin up($bp) {
          @if $bp == xs {
            @media (min-width: 576px) { @content; }
          } @else {
            @content;
          }
        }

        .a {
          @each $bp in (xs, sm) {
            $infix: if($bp == sm, "", "-sm");

            &#{$infix} {
              @include up($bp) {
                flex-flow: row nowrap;

                %ph-#{$bp} {
                  flex-wrap: nowrap;
                }

                > .container {
                  @extend %ph-#{$bp};
                }

                @each $size in (sm, md) {
                  > .container-#{$size} {
                    @extend %ph-#{$bp};
                  }
                }

                .navbar-nav-scroll {
                  overflow: visible;
                }
              }
            }
          }
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @media (min-width: 576px) {
          .a-sm {
            flex-flow: row nowrap;
          }
          .a-sm > .container-md, .a-sm > .container-sm, .a-sm > .container {
            flex-wrap: nowrap;
          }
          .a-sm .navbar-nav-scroll {
            overflow: visible;
          }
        }
        .a {
          flex-flow: row nowrap;
        }
        .a > .container-md, .a > .container-sm, .a > .container {
          flex-wrap: nowrap;
        }
        .a .navbar-nav-scroll {
          overflow: visible;
        }
        CSS;

        expect($this->compiler->compileString($scss))->toEqualCss($expected);
    });

    it('keeps multi-line selector lists wrapped', function () {
        $scss = <<<'SCSS'
        .a1,
        .a2,
        .a3 {
          color: red;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .a1,
        .a2,
        .a3 {
          color: red;
        }
        CSS;

        expect($this->compiler->compileString($scss))->toEqualCss($expected);
    });

    it('does not wrap selector lists written on one line', function () {
        $scss = <<<'SCSS'
        .a, .b {
          color: red;
        }

        .c {
          @extend .b;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .a, .b, .c {
          color: red;
        }
        CSS;

        expect($this->compiler->compileString($scss))->toEqualCss($expected);
    });

    it('keeps extended selectors of a wrapped list wrapped', function () {
        $scss = <<<'SCSS'
        .a,
        .b {
          color: red;
        }

        .c {
          @extend .b;
        }

        .d {
          @extend .b;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .a,
        .b,
        .d,
        .c {
          color: red;
        }
        CSS;

        expect($this->compiler->compileString($scss))->toEqualCss($expected);
    });

    it('keeps wrapped lists wrapped when the parent selector is resolved inside a mixin', function () {
        $scss = <<<'SCSS'
        @mixin hover-focus() {
          &:hover,
          &:focus {
            @content;
          }
        }

        @mixin bg-variant($parent) {
          a#{$parent},
          button#{$parent} {
            @include hover-focus() {
              background-color: blue;
            }
          }
        }

        @include bg-variant(".bg-primary");
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        a.bg-primary:hover, a.bg-primary:focus,
        button.bg-primary:hover,
        button.bg-primary:focus {
          background-color: blue;
        }
        CSS;

        expect($this->compiler->compileString($scss))->toEqualCss($expected);
    });

    it('keeps wrapped lists of imported files wrapped when extends apply', function () {
        $tmpDir = sys_get_temp_dir() . '/scss-php-wrap-import-' . uniqid('', true);

        mkdir($tmpDir, 0777, true);

        file_put_contents($tmpDir . '/_parts.scss', <<<'SCSS'
        .a,
        .b {
          color: red;
        }

        .c {
          @extend .b;
        }
        SCSS);

        $compiler = new Compiler(loader: new Loader([$tmpDir]));
        $css      = $compiler->compileString("@import 'parts';\n");

        expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
        .a,
        .b,
        .c {
          color: red;
        }
        CSS);
    });
});
