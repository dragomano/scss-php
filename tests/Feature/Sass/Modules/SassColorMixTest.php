<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;

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
              value: rgb(50%, 50%, 50%);
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
              background-color: rgb(9.7411764706%, 47.6941176471%, 88.2745098039%);
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
              value: rgb(41.1764705882%, 54.1176470588%, 63.3333333333%);
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
              value: color(rec2020 0.95 0.7 0.15);
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
              value: oklch(65% 0.06 240deg);
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
              value: oklch(65% 0.06 30deg);
            }

            .decreasing {
              value: oklch(65% 0.06 210deg);
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
              value: oklch(65% 0.06 120deg);
            }

            .b {
              value: oklch(65% 0.04 60deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });
});
