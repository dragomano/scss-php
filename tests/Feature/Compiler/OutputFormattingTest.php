<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;
use Tests\ArrayLogger;

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

        expect($css)->toBe('.a{color:#ff000080;border-color:#abcd}');
    });

    it('does not shorten hex literals in expanded style', function () {
        $css = $this->compiler->compileString('.a { color: #ff0000; }');

        $expected = /** @lang text */ <<<'CSS'
        .a {
          color: #f00;
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('does not shorten hex inside strings in compressed style', function () {
        $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));

        $css = $compiler->compileString('.a { content: "#ff0000"; }');

        expect($css)->toBe('.a{content:"#ff0000"}');
    });

    it('preserves functional notation for non-lossless oklch results in compressed style', function () {
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

        expect($css)->toBe('.color-oklch{scale:rgb(170.1523705044,144.612080332,170.1172611061);mix:rgb(95.936325,74.568714,133.208259)}');
    });

    it('preserves fractional rgb functions in compressed style', function () {
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

        expect($css)->toBe('.a{mix:rgb(105,138,161.5);scale:rgb(127.5,0,0);invert:rgb(103.4937692017,61.3720912206,59.430641338)}');
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
        CSS;

        expect($css)->toEqualCss($expected);
    });
});
