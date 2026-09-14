<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

        it('evaluates calc expressions inside CSS filter functions', function () {
            $source = <<<'SCSS'
            $hue: 45deg;

            .test {
              direct: hue-rotate(calc(180deg + 45deg));
              from-var: hue-rotate($hue);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              direct: hue-rotate(225deg);
              from-var: hue-rotate(45deg);
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('collapses trivial calc() values in declarations', function () {
            $source = <<<'SCSS'
            .test { opacity: calc(1.5); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              opacity: 1.5;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates max() when nested calc() resolves to a compatible number', function () {
            $source = <<<'SCSS'
            .test { font-size: max(10px, calc(15.5px)); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              font-size: 15.5px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('unwraps nested calc() in min() when expression cannot be reduced to a number', function () {
            $source = <<<'SCSS'
            .test { value: min(100px, calc(1rem + 10%)); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: min(100px, 1rem + 10%);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves zero units inside nested calculation functions', function () {
            $source = <<<'SCSS'
            .test { padding: max(0px, min(10px, 2vw)); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              padding: max(0px, min(10px, 2vw));
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('unwraps nested calc() inside calc() when value comes from variable', function () {
            $source = <<<'SCSS'
            $width: calc(400px + 10%);
            .sidebar {
              width: $width;
              padding-left: calc($width / 4);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .sidebar {
              width: calc(400px + 10%);
              padding-left: calc((400px + 10%) / 4);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates calculation constants and keeps unknown identifiers untouched', function () {
            $source = <<<'SCSS'
            @use 'sass:math';
            .test {
              pi: calc(pi);
              e: calc(e);
              nan: calc(NaN);
              keep: calc(h + 30deg);
            }
            .cmp {
              gt: calc(infinity) > math.$max-number;
              lt: calc(-infinity) < math.$min-number;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              pi: 3.1415926536;
              e: 2.7182818285;
              nan: calc(NaN);
              keep: calc(h + 30deg);
            }

            .cmp {
              gt: true;
              lt: true;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('normalizes lowercase NaN constants and arithmetic that produces NaN inside calc()', function () {
            $source = <<<'SCSS'
            .test {
              direct: calc(nan);
              product: calc(infinity * 0);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              direct: calc(NaN);
              product: calc(NaN);
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('supports calculation round strategy and step fallback', function () {
            $source = <<<'SCSS'
            $number: 12.5px;
            $step: 15px;

            .post-image {
              padding-left: round(nearest, $number, $step);
              padding-right: round($number + 10px);
              padding-bottom: round($number + 10px, $step + 10%);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .post-image {
              padding-left: 15px;
              padding-right: 23px;
              padding-bottom: round(22.5px, 15px + 10%);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports round strategies up down and to-zero for positive and negative values', function () {
            $source = <<<'SCSS'
            .round-strategies {
              up-pos: round(up, 14px, 5px);
              up-neg: round(up, -14px, 5px);
              down-pos: round(down, 14px, 5px);
              down-neg: round(down, -14px, 5px);
              zero-pos: round(to-zero, 14px, 5px);
              zero-neg: round(to-zero, -14px, 5px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .round-strategies {
              up-pos: 15px;
              up-neg: -10px;
              down-pos: 10px;
              down-neg: -15px;
              zero-pos: 10px;
              zero-neg: -10px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports round nearest tie-breaking for positive and negative values', function () {
            $source = <<<'SCSS'
            .round-nearest {
              nearest-pos: round(nearest, 12.5px, 5px);
              nearest-neg: round(nearest, -12.5px, 5px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .round-nearest {
              nearest-pos: 15px;
              nearest-neg: -15px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps legacy abs behavior with percent and supports math.abs', function () {
            $source = <<<'SCSS'
            @use 'sass:math';
            .post-image {
              padding-left: abs(10px);
              padding-right: math.abs(-7.5%);
              padding-top: abs(1 + 1px);
              padding-bottom: abs(10%);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .post-image {
              padding-left: 10px;
              padding-right: 7.5%;
              padding-top: 2px;
              padding-bottom: 10%;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('distinguishes slash separators from division in declarations', function () {
            $source = <<<'SCSS'
            .child {
              grid-row: 2 / 4;
              grid-column: 1 / 4;
              font: 16px/1.4 Arial;
              margin: (10px + 5px) / 30px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .child {
              grid-row: 2/4;
              grid-column: 1/4;
              font: 16px/1.4 Arial;
              margin: 0.5;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('simplifies calc() division with a unit on the right-hand side', function () {
            $source = <<<'SCSS'
            body {
              width: 6 / 2px;
              height: calc(6 / 2px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            body {
              width: 6/2px;
              height: calc(3 / 1px);
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('simplifies calc() division with a unit on the left-hand side', function () {
            $source = <<<'SCSS'
            body {
              width: 6px / 2;
              height: calc(6px / 2);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            body {
              width: 6px/2;
              height: 3px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });
    });
});
