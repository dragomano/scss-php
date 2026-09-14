<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;

describe('Sass Color Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
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
                ->and($this->logger->records[1]['message'])->toBe('input.scss:3 >>> 0.4');
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
              value: rgb(50%, 50%, 50%);
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
              value: aqua;
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
              color: oklch(50% 0.12 250deg);
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
              value: #ff2233;
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
              color: color(srgb 0.8 0.2 0.1);
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
              value: rgb(50%, 0%, 0%);
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
              value: rgb(50.6666666667%, 44.3137254902%, 49.8039215686%);
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
              value: oklch(80% 0.24 120deg / 0.6);
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
              value: rgb(40.5857918438%, 24.0674867532%, 23.306133858%);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });
});
