<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Tests\ArrayLogger;

describe('Sass Color Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('color.mix()', function () {
        it('compiles color.mix()', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-mix { value: color.mix(#000000, #ffffff, 50%); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-mix {
              value: #808080;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.mix() with non-symmetric weight like dart sass', function () {
            $scss = <<<'SCSS'
            $primary-color: #007bff;
            $secondary-color: #6c757d;
            .class-0 {
              background-color: mix($primary-color, $secondary-color, 77%);
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .class-0 {
              background-color: #197ae1;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.mix() in rgb with float channel result', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-mix { value: color.mix(#036, #d2e1dd, $method: rgb); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-mix {
              value: rgb(105, 138, 161.5);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.mix() in rec2020 with missing channels preserved', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-mix { value: color.mix(color(rec2020 1 0.7 0.1), color(rec2020 0.8 none 0.3), $weight: 75%, $method: rec2020); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-mix {
              value: color(rec2020 .95 .7 .15);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.mix() in oklch with longer hue interpolation', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-mix { value: color.mix(oklch(80% 20% 0deg), oklch(50% 10% 120deg), $method: oklch longer hue); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-mix {
              value: oklch(65% .06 240deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.mix() in oklch with increasing and decreasing hue interpolation', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .increasing { value: color.mix(oklch(80% 20% 300deg), oklch(50% 10% 120deg), $method: oklch increasing hue); }
            .decreasing { value: color.mix(oklch(80% 20% 300deg), oklch(50% 10% 120deg), $method: oklch decreasing hue); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .increasing {
              value: oklch(65% .06 30deg);
            }
            .decreasing {
              value: oklch(65% .06 210deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.mix() in oklch with missing channels preserved', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { value: color.mix(oklch(80% 20% none), oklch(50% 10% 120deg), $method: oklch); }
            .b { value: color.mix(oklch(80% none 0deg), oklch(50% 10% 120deg), $method: oklch); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: oklch(65% .06 120deg);
            }
            .b {
              value: oklch(65% .04 60deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.alpha()', function () {
        it('keeps color.alpha() and color.opacity() working without deprecation warnings', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            @debug color.alpha(#e1d7d2);
            @debug color.opacity(rgb(210 225 221 / 0.4));
            SCSS;

            $this->compiler->compileString($scss);

            expect($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> 1')
                ->and($this->logger->records[1]['message'])->toBe('input.scss:3 >>> .4');
        });
    });

    describe('color.grayscale()', function () {
        it('converts red to grey via oklch', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-grayscale { value: color.grayscale(#ff0000); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-grayscale {
              value: rgb(127.5, 127.5, 127.5);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('grayscales oklch() by zeroing chroma', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.grayscale(oklch(50% 80% 270deg)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .a {
              color: oklch(50% 0 270deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('grayscales color(srgb) by converting through oklch', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.grayscale(color(srgb 0.4 0.2 0.6)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toContain('color(srgb');
        });
    });

    describe('color.complement()', function () {
        it('returns cyan for red', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-complement { value: color.complement(#ff0000); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-complement {
              value: #0ff;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('computes complement of oklch() natively', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.complement(oklch(50% 0.12 70deg), oklch); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .a {
              color: oklch(50% .12 250deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('computes complement of legacy color in oklch space', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.complement(#6b717f, oklch); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toContain('rgb(');
        });
    });

    describe('color.change()', function () {
        it('changes red channel of hex color', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-change { value: color.change(#112233, $red: 255); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-change {
              value: #f23;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('changes color(srgb) channels in 0-1 range', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.change(color(srgb 0 0.2 0.4), $red: 0.8, $blue: 0.1); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .a {
              color: color(srgb .8 .2 .1);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('changes legacy hex color in oklch space', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.change(#998099, $lightness: 30%, $space: oklch); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toContain('rgb(');
        });
    });

    describe('color.adjust()', function () {
        it('adjusts blue channel of hex color', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-adjust { value: color.adjust(#112233, $blue: 10); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-adjust {
              value: #11223d;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('adjusts lab() channels natively', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.adjust(lab(40% 30 40), $lightness: 10%, $a: -20); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .a {
              color: lab(50% 10 40);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('adjusts legacy hex color in oklch space', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { color: color.adjust(#d2e1dd, $hue: 45deg, $space: oklch); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toContain('rgb(');
        });
    });

    describe('color.scale()', function () {
        it('compiles color.scale()', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-scale { value: color.scale(#000000, $red: 50%); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-scale {
              value: rgb(127.5, 0, 0);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.scale() with float rgb channels', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-scale { value: color.scale(#6b717f, $red: 15%); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-scale {
              value: rgb(129.2, 113, 127);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.scale() in oklch space and preserves native oklch output', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-scale { value: color.scale(oklch(80% 20% 120deg), $chroma: 50%, $alpha: -40%); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-scale {
              value: oklch(80% .24 120deg / .6);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.invert()', function () {
        it('compiles color.invert() in display-p3 space', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-invert { value: color.invert(#550e0c, 20%, $space: display-p3); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-invert {
              value: rgb(103.4937692017, 61.3720912206, 59.430641338);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.ie-hex-str()', function () {
        it('global ie-hex-str() outputs IE alpha-first format', function () {
            $scss = <<<'SCSS'
            .color-ie { value: ie-hex-str(#33669980); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-ie {
              value: #80336699;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('module ie-hex-str() outputs IE alpha-first format', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-ie { value: color.ie-hex-str(#33669980); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-ie {
              value: #80336699;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.whiteness()', function () {
        it('returns 20% for #336699', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-whiteness { value: color.whiteness(#336699); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-whiteness {
              value: 20%;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.blackness()', function () {
        it('returns 40% for #336699', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-blackness { value: color.blackness(#336699); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-blackness {
              value: 40%;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.channel()', function () {
        it('returns red channel value of hex color', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-channel { value: color.channel(#336699, 'red'); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-channel {
              value: 51;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.opacity()', function () {
        it('returns alpha fraction of semitransparent hex', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-opacity { value: color.opacity(#33669980); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-opacity {
              value: .5019607843;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.is-in-gamut()', function () {
        it('returns true for in-gamut hex color', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-in-gamut { value: color.is-in-gamut(#b37399); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-in-gamut {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns true for in-gamut color(srgb)', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-in-gamut { value: color.is-in-gamut(color(srgb 0.5 0.5 0.5)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-in-gamut {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns false for out-of-gamut color(srgb)', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-in-gamut { value: color.is-in-gamut(color(srgb 1.2 0 0)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-in-gamut {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns true for in-gamut color(display-p3)', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-in-gamut { value: color.is-in-gamut(color(display-p3 0.9 0 0)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-in-gamut {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns false for out-of-gamut color(display-p3)', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-in-gamut { value: color.is-in-gamut(color(display-p3 1.2 0 0)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-in-gamut {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.is-legacy()', function () {
        it('returns true for hex color', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-legacy { value: color.is-legacy(#336699); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-legacy {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.is-missing()', function () {
        it('compiles color.is-missing()', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-missing { value: color.is-missing(#336699, red); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-missing {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.is-missing() for hue after color.to-space() lch conversion', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-missing { value: color.is-missing(color.to-space(grey, lch), hue); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-missing {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.is-powerless()', function () {
        it('returns true for grey hue', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-is-powerless { value: color.is-powerless(#808080, hue); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-is-powerless {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.to-space()', function () {
        it('compiles color.to-space()', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space { value: color.to-space(#336699, hsl); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              value: hsl(210, 50%, 40%);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-space() for srgb', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space { value: color.to-space(#336699, srgb); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              value: color(srgb .2 .4 .6);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-space() for display-p3', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space { value: color.to-space(#036, display-p3); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              value: color(display-p3 .0690923275 .196438359 .3861624224);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-space() from wide-gamut display-p3 without collapsing to srgb first', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space {
              xyz: color.to-space(color(display-p3 1 0 0), xyz);
              lab: color.to-space(color(display-p3 1 0 0), lab);
              oklab: color.to-space(color(display-p3 1 0 0), oklab);
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              xyz: color(xyz .4865709486 .2289745641 0);
              lab: lab(56.2077729169% 94.464418467 98.8921195438);
              oklab: oklab(64.8574075144% .2620417594 .1450019071);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-space() from oklab to rgb with float channels', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space { value: color.to-space(oklab(44% 0.09 -0.13), rgb); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              value: rgb(103.1328905413, 50.9728129811, 150.8382222315);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-space() from lch with missing lightness to oklch', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space { value: color.to-space(lch(none 10% 30deg), oklch); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              value: oklch(none .3782382557 11.1889157942deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-space() from oklch with missing lightness to lch', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space { value: color.to-space(oklch(none 0.2 120deg), lch); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              value: lch(none 26.4928808578 116.9374721467deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-space() unchanged for same generic space with missing channels', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-space { value: color.to-space(color(rec2020 1 none .3), rec2020); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-space {
              value: color(rec2020 1 none .3);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.space()', function () {
        it('returns rgb, hsl, and xyz for respective colors', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .a { value: color.space(#036); }
            .b { value: color.space(hsl(120deg 40% 50%)); }
            .c { value: color.space(color(xyz-d65 0.1 0.2 0.3)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .a {
              value: rgb;
            }
            .b {
              value: hsl;
            }
            .c {
              value: xyz;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.same()', function () {
        it('returns true for identical colors', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-same { value: color.same(#ff0000, #ff0000); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-same {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns true after to-space() oklch conversion', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-same { value: color.same(#036, color.to-space(#036, oklch)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-same {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('color.to-gamut()', function () {
        it('compiles color.to-gamut() in original oklch space for local-minde', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-gamut { value: color.to-gamut(oklch(60% 70% 20deg), $space: rgb, $method: local-minde); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-gamut {
              value: oklch(61.2058837805% .2466052582 22.0773321712deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-gamut() unchanged for rgb color already in gamut', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-gamut { value: color.to-gamut(#036, $method: local-minde); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-gamut {
              value: #036;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-gamut() in original oklch space for clip', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-gamut { value: color.to-gamut(oklch(60% 70% 20deg), $space: rgb, $method: clip); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-gamut {
              value: oklch(62.5026608983% .2528579733 24.1000460045deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('global color functions', function () {
        describe('adjust-hue()', function () {
            it('adjusts hue by 120 degrees', function () {
                $scss = <<<'SCSS'
                .color-adjust-hue { value: adjust-hue(#ff0000, 120deg); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-adjust-hue {
                  value: #0f0;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('lighten()', function () {
            it('lightens black by 20%', function () {
                $scss = <<<'SCSS'
                .color-lighten { value: lighten(#000000, 20%); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-lighten {
                  value: #333;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('darken()', function () {
            it('darkens white by 20%', function () {
                $scss = <<<'SCSS'
                .color-darken { value: darken(#ffffff, 20%); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-darken {
                  value: #ccc;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('returns black at 30% boundary', function () {
                $scss = <<<'SCSS'
                .color-darken-boundary { value: darken(#036, 30%); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-darken-boundary {
                  value: black;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('fade-in()', function () {
            it('increases opacity by 0.2', function () {
                $scss = <<<'SCSS'
                .color-fade-in { value: fade-in(#11223380, 0.2); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-fade-in {
                  value: rgba(17, 34, 51, .7019607843);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('fade-out()', function () {
            it('decreases opacity by 0.2', function () {
                $scss = <<<'SCSS'
                .color-fade-out { value: fade-out(#112233cc, 0.2); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-fade-out {
                  value: rgba(17, 34, 51, .6);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('saturate()', function () {
            it('passes through with single percentage argument', function () {
                $scss = <<<'SCSS'
                .color-global-saturate-css { value: saturate(119%); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-global-saturate-css {
                  value: saturate(119%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('evaluates when percentage is wrapped in calc()', function () {
                $scss = <<<'SCSS'
                $c: #336699;
                $i: 2;
                .color-saturate-calc { value: saturate($c, calc($i * 2%)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .color-saturate-calc {
                  value: rgb(46.92, 102, 157.08);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('color()', function () {
            it('passes through with css variable', function () {
                $css = $this->compiler->compileString('.a { color: color(display-p3 var(--peach)); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(display-p3 var(--peach));
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs plain channel numbers and omits default alpha', function () {
                $css = $this->compiler->compileString('.a { color: color(srgb 0.1 0.6 1); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(srgb .1 .6 1);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs percentage channels as 0-1 values with alpha', function () {
                $css = $this->compiler->compileString('.a { color: color(xyz 30% 0% 90% / 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(xyz .3 0 .9 / .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves out-of-gamut channel values without clamping', function () {
                $css = $this->compiler->compileString('.a { color: color(srgb 1.2 -0.1 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(srgb 1.2 -.1 .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('omits alpha when explicitly set to 1', function () {
                $css = $this->compiler->compileString('.a { color: color(srgb 0.5 0.5 0.5 / 1); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(srgb .5 .5 .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('lch()', function () {
            it('converts turn angle to degrees', function () {
                $css = $this->compiler->compileString('.a { color: lch(80% 75 0.2turn); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lch(80% 75 72deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('converts turn angle to degrees with alpha', function () {
                $css = $this->compiler->compileString('.a { color: lch(80% 75 0.2turn / 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lch(80% 75 72deg / .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves deg unit unchanged', function () {
                $css = $this->compiler->compileString('.a { color: lch(50% 10 270deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lch(50% 10 270deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('scales percentage chroma', function () {
                $css = $this->compiler->compileString('.a { color: lch(80% 50% 30deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lch(80% 75 30deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves large chroma in expanded style', function () {
                $css = $this->compiler->compileString('.a { color: lch(50% 200 120deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lch(50% 200 120deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('oklch()', function () {
            it('converts turn angle to degrees', function () {
                $css = $this->compiler->compileString('.a { color: oklch(80% 0.2 0.2turn); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(80% .2 72deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves deg unit unchanged', function () {
                $css = $this->compiler->compileString('.a { color: oklch(50% 0.3 270deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(50% .3 270deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('scales percentage chroma', function () {
                $css = $this->compiler->compileString('.a { color: oklch(80% 50% 30deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(80% .2 30deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('passes none channel through as CSS', function () {
                $css = $this->compiler->compileString('.a { color: oklch(none 0.1 180); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(none .1 180);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs correct channels in expanded style', function () {
                $css = $this->compiler->compileString('.a { color: oklch(70% 0.15 250deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(70% .15 250deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('lab()', function () {
            it('scales percentage a/b channels', function () {
                $css = $this->compiler->compileString('.a { color: lab(80% 0% 20%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lab(80% 0 25);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('passes through absolute channels unchanged', function () {
                $css = $this->compiler->compileString('.a { color: lab(80% 0 25); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lab(80% 0 25);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs unbounded a/b channels in expanded style', function () {
                $css = $this->compiler->compileString('.a { color: lab(50% 80 -90); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: lab(50% 80 -90);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('oklab()', function () {
            it('scales percentage a/b channels', function () {
                $css = $this->compiler->compileString('.a { color: oklab(80% 20% -10%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklab(80% .08 -.04);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs correct channels in expanded style', function () {
                $css = $this->compiler->compileString('.a { color: oklab(70% 0.05 -0.1); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklab(70% .05 -.1);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('hwb()', function () {
            it('preserves functional notation', function () {
                $css = $this->compiler->compileString('.a { color: hwb(210 0% 60%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hwb(210 0% 60%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('normalizes over-specified whiteness+blackness', function () {
                $css = $this->compiler->compileString('.a { color: hwb(210 60% 60%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hwb(210 50% 50%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves with alpha', function () {
                $css = $this->compiler->compileString('.a { color: hwb(210 0% 60% / 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hwb(210 0% 60% / .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('converts to hex when outputHexColors is enabled', function () {
                $compiler = new Compiler(new CompilerOptions(outputHexColors: true));
                $css = $compiler->compileString('.a { color: hwb(210 20% 30%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: #3373b3;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('hsl()', function () {
            it('converts space-separated with deg unit to comma format', function () {
                $css = $this->compiler->compileString('.a { color: hsl(210deg 100% 20%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsl(210, 100%, 20%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves legacy comma-separated syntax unchanged', function () {
                $css = $this->compiler->compileString('.a { color: hsl(210, 100%, 20%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsl(210, 100%, 20%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves legacy comma format with full saturation', function () {
                $css = $this->compiler->compileString('.color-hsl { value: hsl(120, 100%, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .color-hsl {
                  value: hsl(120, 100%, 50%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('passes none channel through as CSS', function () {
                $css = $this->compiler->compileString('.a { color: hsl(none 50% 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsl(none 50% 50%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('normalizes hue while preserving missing lightness', function () {
                $css = $this->compiler->compileString('.a { color: hsl(480 50% none); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsl(120 50% none);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves saturation values above one hundred percent', function () {
                $css = $this->compiler->compileString('.a { color: hsl(120, 120%, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsl(120, 120%, 50%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('converts to hex when outputHexColors is enabled', function () {
                $compiler = new Compiler(new CompilerOptions(outputHexColors: true));
                $css = $compiler->compileString('.a { color: hsl(210deg 40% 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: #4d80b3;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('hsla()', function () {
            it('preserves comma-separated syntax with alpha', function () {
                $css = $this->compiler->compileString('.a { color: hsla(210, 100%, 20%, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsla(210, 100%, 20%, .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves comma format with different hue', function () {
                $css = $this->compiler->compileString('.color-hsla { value: hsla(240, 100%, 50%, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .color-hsla {
                  value: hsla(240, 100%, 50%, .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('rgb()', function () {
            it('preserves comma-separated syntax by default', function () {
                $css = $this->compiler->compileString('.a { color: rgb(255, 0, 0); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgb(255, 0, 0);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('converts to hex when outputHexColors is enabled', function () {
                $compiler = new Compiler(new CompilerOptions(outputHexColors: true));
                $css = $compiler->compileString('.a { color: rgb(255, 0, 0); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: #f00;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies percentage alpha with color constructor', function () {
                $css = $this->compiler->compileString('.a { color: rgb(#f2ece4, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(242, 236, 228, .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies decimal alpha with color constructor', function () {
                $css = $this->compiler->compileString('.a { color: rgb(#f2ece4, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(242, 236, 228, .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('rgba()', function () {
            it('preserves comma-separated 4-arg syntax', function () {
                $css = $this->compiler->compileString('.a { color: rgba(0, 0, 0, 0.3); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(0, 0, 0, .3);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('converts to hex when outputHexColors is enabled', function () {
                $compiler = new Compiler(new CompilerOptions(outputHexColors: true));
                $css = $compiler->compileString('.a { color: rgba(17, 34, 51, 0.7019607843); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: #112233b3;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('scales percentage channels to absolute values', function () {
                $css = $this->compiler->compileString('.a { color: rgba(95%, 92.5%, 89.5%, 0.2); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(242.25, 235.875, 228.225, .2);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('resolves nested color to hex when outer alpha is 1', function () {
                $css = $this->compiler->compileString('.a { color: rgba(rgba(0, 51, 102, 0.5), 1); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: #036;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies decimal alpha with color constructor', function () {
                $css = $this->compiler->compileString('.color-rgba { value: rgba(#ff0000, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .color-rgba {
                  value: rgba(255, 0, 0, .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies percentage alpha to 4-arg form', function () {
                $css = $this->compiler->compileString('.a { color: rgba(255, 0, 0, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(255, 0, 0, .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies percentage alpha with color constructor', function () {
                $css = $this->compiler->compileString('.a { color: rgba(#ff0000, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(255, 0, 0, .5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });
    });

    it('logs deprecations', function () {
        $scss = <<<'SCSS'
        @use "sass:color";
        @debug adjust-hue(#036, 45);
        @debug alpha(opacity=20);
        @debug alpha(#e1d7d2);
        @debug color.blackness(#e1d7d2);
        @debug blackness(black);
        @debug color.blue(#e1d7d2);
        @debug blue(black);
        @debug desaturate(#d2e1dd, 30%);
        @debug color.green(#e1d7d2);
        @debug green(black);
        @debug color.hue(#e1d7d2);
        @debug color.hue(#f2ece4);
        @debug hue(#dadbdf);
        @debug lighten(#e1d7d2, 30%);
        @debug lighten(#6b717f, 20%);
        @debug lighten(#036, 60%);
        @debug darken(#036, 30%);
        @debug color.lightness(#e1d7d2);
        @debug color.lightness(#f2ece4);
        @debug lightness(#dadbdf);
        @debug opacify(rgba(#036, 0.7), 0.3);
        @debug opacify(rgba(#6b717f, 0.5), 0.2);
        @debug fade-in(rgba(#e1d7d2, 0.5), 0.4);
        @debug color.red(#e1d7d2);
        @debug red(black);
        @debug saturate(#0e4982, 30%);
        @debug saturate(#c69, 20%);
        @debug color.saturation(#e1d7d2);
        @debug color.saturation(#f2ece4);
        @debug saturation(#dadbdf);
        @debug transparentize(rgba(#036, 0.3), 0.3);
        @debug transparentize(rgba(#6b717f, 0.5), 0.2);
        @debug fade-out(rgba(#e1d7d2, 0.5), 0.4);
        @debug color.whiteness(#e1d7d2);
        @debug color.whiteness(white);
        @debug whiteness(black);
        SCSS;

        $this->compiler->compileString($scss);

        $messages = implode("\n", array_column($this->logger->records, 'message'));

        expect($this->logger->records)->toHaveCount(71)
            ->and($messages)->toContain('adjust-hue() is deprecated. Suggestion: color.adjust(#036, $hue: 45deg)')
            ->and($messages)->toContain('rgb(25.5, 0, 102)')
            ->and($messages)->toContain('alpha(opacity=20)')
            ->and($messages)->toContain('alpha() is deprecated. Suggestion: color.channel(#e1d7d2, "alpha")')
            ->and($messages)->toContain('color.blackness() is deprecated. Suggestion: color.channel(#e1d7d2, "blackness", $space: hwb)')
            ->and($messages)->toContain('blackness() is deprecated. Suggestion: color.channel(black, "blackness", $space: hwb)')
            ->and($messages)->toContain('color.blue() is deprecated. Suggestion: color.channel(#e1d7d2, "blue", $space: rgb)')
            ->and($messages)->toContain('blue() is deprecated. Suggestion: color.channel(black, "blue", $space: rgb)')
            ->and($messages)->toContain('desaturate() is deprecated. Suggestions: color.scale(#d2e1dd, $saturation: -100%), or color.adjust(#d2e1dd, $saturation: -30%)')
            ->and($messages)->toContain('rgb(217.5000009, 217.5000009, 217.5000009)')
            ->and($messages)->toContain('color.green() is deprecated. Suggestion: color.channel(#e1d7d2, "green", $space: rgb)')
            ->and($messages)->toContain('green() is deprecated. Suggestion: color.channel(black, "green", $space: rgb)')
            ->and($messages)->toContain('color.hue() is deprecated. Suggestion: color.channel(#e1d7d2, "hue", $space: hsl)')
            ->and($messages)->toContain('hue() is deprecated. Suggestion: color.channel(#dadbdf, "hue", $space: hsl)')
            ->and($messages)->toContain('34.2857142857deg')
            ->and($messages)->toContain('lighten() is deprecated. Suggestions: color.scale(#e1d7d2, $lightness: 100%), or color.adjust(#e1d7d2, $lightness: 30%)')
            ->and($messages)->toContain('lighten() is deprecated. Suggestions: color.scale(#6b717f, $lightness: 36.9565217793%), or color.adjust(#6b717f, $lightness: 20%)')
            ->and($messages)->toContain('lighten() is deprecated. Suggestions: color.scale(#036, $lightness: 75%), or color.adjust(#036, $lightness: 60%)')
            ->and($messages)->toContain('#9cf')
            ->and($messages)->toContain('darken() is deprecated. Suggestions: color.scale(#036, $lightness: -100%), or color.adjust(#036, $lightness: -30%)')
            ->and($messages)->toContain('black')
            ->and($messages)->toContain('color.lightness() is deprecated. Suggestion: color.channel(#e1d7d2, "lightness", $space: hsl)')
            ->and($messages)->toContain('lightness() is deprecated. Suggestion: color.channel(#dadbdf, "lightness", $space: hsl)')
            ->and($messages)->toContain('opacify() is deprecated. Suggestions: color.scale(rgba(0, 51, 102, 0.7), $alpha: 100%), or color.adjust(rgba(0, 51, 102, 0.7), $alpha: 0.3)')
            ->and($messages)->toContain('fade-in() is deprecated. Suggestions: color.scale(rgba(225, 215, 210, 0.5), $alpha: 80%), or color.adjust(rgba(225, 215, 210, 0.5), $alpha: 0.4)')
            ->and($messages)->toContain('rgba(225, 215, 210, .9)')
            ->and($messages)->toContain('color.red() is deprecated. Suggestion: color.channel(#e1d7d2, "red", $space: rgb)')
            ->and($messages)->toContain('red() is deprecated. Suggestion: color.channel(black, "red", $space: rgb)')
            ->and($messages)->toContain('saturate() is deprecated. Suggestions: color.scale(#0e4982, $saturation: 100%), or color.adjust(#0e4982, $saturation: 30%)')
            ->and($messages)->toContain('saturate() is deprecated. Suggestions: color.scale(#c69, $saturation: 40%), or color.adjust(#c69, $saturation: 20%)')
            ->and($messages)->toContain('rgb(224.4, 81.6, 153)')
            ->and($messages)->toContain('color.saturation() is deprecated. Suggestion: color.channel(#e1d7d2, "saturation", $space: hsl)')
            ->and($messages)->toContain('saturation() is deprecated. Suggestion: color.channel(#dadbdf, "saturation", $space: hsl)')
            ->and($messages)->toContain('transparentize() is deprecated. Suggestions: color.scale(rgba(0, 51, 102, 0.3), $alpha: -100%), or color.adjust(rgba(0, 51, 102, 0.3), $alpha: -0.3)')
            ->and($messages)->toContain('fade-out() is deprecated. Suggestions: color.scale(rgba(225, 215, 210, 0.5), $alpha: -80%), or color.adjust(rgba(225, 215, 210, 0.5), $alpha: -0.4)')
            ->and($messages)->toContain('rgba(225, 215, 210, .1)')
            ->and($messages)->toContain('color.whiteness() is deprecated. Suggestion: color.channel(#e1d7d2, "whiteness", $space: hwb)')
            ->and($messages)->toContain('whiteness() is deprecated. Suggestion: color.channel(black, "whiteness", $space: hwb)');
    });
});
