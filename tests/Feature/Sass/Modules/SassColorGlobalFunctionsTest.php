<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;
use Tests\Support\ArrayLogger;

describe('Sass Color Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
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
                  value: lime;
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
                  value: #333333;
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
                  value: #cccccc;
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
                  value: rgba(17, 34, 51, 0.7019607843);
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
                  value: rgba(17, 34, 51, 0.6);
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
                  value: rgb(18.4%, 40%, 61.6%);
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
                  color: color(srgb 0.1 0.6 1);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs percentage channels as 0-1 values with alpha', function () {
                $css = $this->compiler->compileString('.a { color: color(xyz 30% 0% 90% / 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(xyz 0.3 0 0.9 / 0.5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves out-of-gamut channel values without clamping', function () {
                $css = $this->compiler->compileString('.a { color: color(srgb 1.2 -0.1 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(srgb 1.2 -0.1 0.5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('omits alpha when explicitly set to 1', function () {
                $css = $this->compiler->compileString('.a { color: color(srgb 0.5 0.5 0.5 / 1); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: color(srgb 0.5 0.5 0.5);
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
                  color: lch(80% 75 72deg / 0.5);
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
                  color: oklch(80% 0.2 72deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves deg unit unchanged', function () {
                $css = $this->compiler->compileString('.a { color: oklch(50% 0.3 270deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(50% 0.3 270deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('scales percentage chroma', function () {
                $css = $this->compiler->compileString('.a { color: oklch(80% 50% 30deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(80% 0.2 30deg);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('passes none channel through as CSS', function () {
                $css = $this->compiler->compileString('.a { color: oklch(none 0.1 180); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(none 0.1 180);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs correct channels in expanded style', function () {
                $css = $this->compiler->compileString('.a { color: oklch(70% 0.15 250deg); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklch(70% 0.15 250deg);
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
                  color: oklab(80% 0.08 -0.04);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('outputs correct channels in expanded style', function () {
                $css = $this->compiler->compileString('.a { color: oklab(70% 0.05 -0.1); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: oklab(70% 0.05 -0.1);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('hwb()', function () {
            it('converts to hex', function () {
                $css = $this->compiler->compileString('.a { color: hwb(210 0% 60%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: #003366;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('normalizes over-specified whiteness+blackness', function () {
                $css = $this->compiler->compileString('.a { color: hwb(210 60% 60%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsl(0, 0%, 50%);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves with alpha', function () {
                $css = $this->compiler->compileString('.a { color: hwb(210 0% 60% / 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsla(210, 100%, 20%, 0.5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('converts to hex in compressed style', function () {
                $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));
                $css = $compiler->compileString('.a { color: hwb(210 20% 30%); }');

                expect($css)->toBe('.a{color:#3373b3}');
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
                  color: hsl(120deg 50% none);
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

            it('converts to hex in compressed style', function () {
                $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));
                $css = $compiler->compileString('.a { color: hsl(210deg 40% 50%); }');

                expect($css)->toBe('.a{color:#4d80b3}');
            });
        });

        describe('hsla()', function () {
            it('preserves comma-separated syntax with alpha', function () {
                $css = $this->compiler->compileString('.a { color: hsla(210, 100%, 20%, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: hsla(210, 100%, 20%, 0.5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('preserves comma format with different hue', function () {
                $css = $this->compiler->compileString('.color-hsla { value: hsla(240, 100%, 50%, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .color-hsla {
                  value: hsla(240, 100%, 50%, 0.5);
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

            it('converts to hex in compressed style', function () {
                $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));
                $css = $compiler->compileString('.a { color: rgb(255, 0, 0); }');

                expect($css)->toBe('.a{color:#f00}');
            });

            it('applies percentage alpha with color constructor', function () {
                $css = $this->compiler->compileString('.a { color: rgb(#f2ece4, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(242, 236, 228, 0.5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies decimal alpha with color constructor', function () {
                $css = $this->compiler->compileString('.a { color: rgb(#f2ece4, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(242, 236, 228, 0.5);
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
                  color: rgba(0, 0, 0, 0.3);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('converts to hex in compressed style', function () {
                $compiler = new Compiler(new CompilerOptions(style: Style::COMPRESSED));
                $css = $compiler->compileString('.a { color: rgba(17, 34, 51, 0.7019607843); }');

                expect($css)->toBe('.a{color:#112233b3}');
            });

            it('scales percentage channels to absolute values', function () {
                $css = $this->compiler->compileString('.a { color: rgba(95%, 92.5%, 89.5%, 0.2); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(95%, 92.5%, 89.5%, 0.2);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('resolves nested color to hex when outer alpha is 1', function () {
                $css = $this->compiler->compileString('.a { color: rgba(rgba(0, 51, 102, 0.5), 1); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: #003366;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies decimal alpha with color constructor', function () {
                $css = $this->compiler->compileString('.color-rgba { value: rgba(#ff0000, 0.5); }');

                $expected = /** @lang text */ <<<'CSS'
                .color-rgba {
                  value: rgba(255, 0, 0, 0.5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies percentage alpha to 4-arg form', function () {
                $css = $this->compiler->compileString('.a { color: rgba(255, 0, 0, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(255, 0, 0, 0.5);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('applies percentage alpha with color constructor', function () {
                $css = $this->compiler->compileString('.a { color: rgba(#ff0000, 50%); }');

                $expected = /** @lang text */ <<<'CSS'
                .a {
                  color: rgba(255, 0, 0, 0.5);
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
            ->and($messages)->toContain('rgb(10%, 0%, 40%)')
            ->and($messages)->toContain('alpha(opacity=20)')
            ->and($messages)->toContain('alpha() is deprecated. Suggestion: color.channel(#e1d7d2, "alpha")')
            ->and($messages)->toContain('color.blackness() is deprecated. Suggestion: color.channel(#e1d7d2, "blackness", $space: hwb)')
            ->and($messages)->toContain('blackness() is deprecated. Suggestion: color.channel(black, "blackness", $space: hwb)')
            ->and($messages)->toContain('color.blue() is deprecated. Suggestion: color.channel(#e1d7d2, "blue", $space: rgb)')
            ->and($messages)->toContain('blue() is deprecated. Suggestion: color.channel(black, "blue", $space: rgb)')
            ->and($messages)->toContain('desaturate() is deprecated. Suggestions: color.scale(#d2e1dd, $saturation: -100%), or color.adjust(#d2e1dd, $saturation: -30%)')
            ->and($messages)->toContain('rgb(85.2941176471%, 85.2941176471%, 85.2941176471%)')
            ->and($messages)->toContain('color.green() is deprecated. Suggestion: color.channel(#e1d7d2, "green", $space: rgb)')
            ->and($messages)->toContain('green() is deprecated. Suggestion: color.channel(black, "green", $space: rgb)')
            ->and($messages)->toContain('color.hue() is deprecated. Suggestion: color.channel(#e1d7d2, "hue", $space: hsl)')
            ->and($messages)->toContain('hue() is deprecated. Suggestion: color.channel(#dadbdf, "hue", $space: hsl)')
            ->and($messages)->toContain('34.2857142857deg')
            ->and($messages)->toContain('lighten() is deprecated. Suggestions: color.scale(#e1d7d2, $lightness: 100%), or color.adjust(#e1d7d2, $lightness: 30%)')
            ->and($messages)->toContain('lighten() is deprecated. Suggestions: color.scale(#6b717f, $lightness: 36.9565217793%), or color.adjust(#6b717f, $lightness: 20%)')
            ->and($messages)->toContain('lighten() is deprecated. Suggestions: color.scale(#036, $lightness: 75%), or color.adjust(#036, $lightness: 60%)')
            ->and($messages)->toContain('#99ccff')
            ->and($messages)->toContain('darken() is deprecated. Suggestions: color.scale(#036, $lightness: -100%), or color.adjust(#036, $lightness: -30%)')
            ->and($messages)->toContain('black')
            ->and($messages)->toContain('color.lightness() is deprecated. Suggestion: color.channel(#e1d7d2, "lightness", $space: hsl)')
            ->and($messages)->toContain('lightness() is deprecated. Suggestion: color.channel(#dadbdf, "lightness", $space: hsl)')
            ->and($messages)->toContain('opacify() is deprecated. Suggestions: color.scale(rgba(0, 51, 102, 0.7), $alpha: 100%), or color.adjust(rgba(0, 51, 102, 0.7), $alpha: 0.3)')
            ->and($messages)->toContain('fade-in() is deprecated. Suggestions: color.scale(rgba(225, 215, 210, 0.5), $alpha: 80%), or color.adjust(rgba(225, 215, 210, 0.5), $alpha: 0.4)')
            ->and($messages)->toContain('rgba(225, 215, 210, 0.9)')
            ->and($messages)->toContain('color.red() is deprecated. Suggestion: color.channel(#e1d7d2, "red", $space: rgb)')
            ->and($messages)->toContain('red() is deprecated. Suggestion: color.channel(black, "red", $space: rgb)')
            ->and($messages)->toContain('saturate() is deprecated. Suggestions: color.scale(#0e4982, $saturation: 100%), or color.adjust(#0e4982, $saturation: 30%)')
            ->and($messages)->toContain('saturate() is deprecated. Suggestions: color.scale(#c69, $saturation: 40%), or color.adjust(#c69, $saturation: 20%)')
            ->and($messages)->toContain('rgb(88%, 32%, 60%)')
            ->and($messages)->toContain('color.saturation() is deprecated. Suggestion: color.channel(#e1d7d2, "saturation", $space: hsl)')
            ->and($messages)->toContain('saturation() is deprecated. Suggestion: color.channel(#dadbdf, "saturation", $space: hsl)')
            ->and($messages)->toContain('transparentize() is deprecated. Suggestions: color.scale(rgba(0, 51, 102, 0.3), $alpha: -100%), or color.adjust(rgba(0, 51, 102, 0.3), $alpha: -0.3)')
            ->and($messages)->toContain('fade-out() is deprecated. Suggestions: color.scale(rgba(225, 215, 210, 0.5), $alpha: -80%), or color.adjust(rgba(225, 215, 210, 0.5), $alpha: -0.4)')
            ->and($messages)->toContain('rgba(225, 215, 210, 0.1)')
            ->and($messages)->toContain('color.whiteness() is deprecated. Suggestion: color.channel(#e1d7d2, "whiteness", $space: hwb)')
            ->and($messages)->toContain('whiteness() is deprecated. Suggestion: color.channel(black, "whiteness", $space: hwb)');
    });
});
