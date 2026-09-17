<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;

describe('Sass Color Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
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
              value: color(srgb 0.2 0.4 0.6);
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
              value: color(display-p3 0.0690923275 0.196438359 0.3861624224);
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
              xyz: color(xyz 0.4865709486 0.2289745641 0);
              lab: lab(56.2077729169% 94.464418467 98.8921195438);
              oklab: oklab(64.8574075144% 0.2620417594 0.1450019071);
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
              value: rgb(40.4442708005%, 19.9893384239%, 59.1522440124%);
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
              value: oklch(none 0.3782382557 11.1889157942deg);
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
              value: color(rec2020 1 none 0.3);
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
});
