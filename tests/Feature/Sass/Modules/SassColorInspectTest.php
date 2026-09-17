<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;

describe('Sass Color Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
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
              value: 0.5019607843;
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
            .color-is-missing { value: color.is-missing(#336699, "red"); }
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
            .color-is-missing { value: color.is-missing(color.to-space(grey, lch), "hue"); }
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
});
