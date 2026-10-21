<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Escaped percentage units', function () {
    it('keeps escaped percentages distinct from percentage values', function (string $number) {
        $source = <<<SCSS
        @use "sass:math";
        a {
          unit: math.unit($number);
          percent: $number == 1%;
          escaped: $number == 1\\%;
          value: $number;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        a {
          unit: "\\%";
          percent: false;
          escaped: true;
          value: 1\%;
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    })->with([
        'simple escape'         => ['1\%'],
        'hex escape'            => ['1\25'],
        'padded hex escape'     => ['1\000025'],
        'terminated hex escape' => ['1\25 '],
    ]);

    it('normalizes escaped letters and preserves percentages within unit names', function () {
        $source = <<<'SCSS'
        @use "sass:math";
        a {
          normalized: math.unit(1\65 m);
          equal: 1\65 m == 1em;
          percentage: math.unit(1%);
          prefixed: math.unit(1foo\%);
          suffixed: math.unit(1\%x);
          hex-name: math.unit(1foo\25 x);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        a {
          normalized: "em";
          equal: true;
          percentage: "%";
          prefixed: "foo\\%";
          suffixed: "\\%x";
          hex-name: "foo\\%x";
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });

    it('uses canonical escaped units in arithmetic and compatibility checks', function () {
        $source = <<<'SCSS'
        @use "sass:math";
        a {
          sum: 1\% + 2\25;
          ratio: math.div(4\%, 2\25);
          compatible: math.compatible(1\%, 1%);
          same: math.compatible(1\%, 1\25);
          percent: 1% + 2%;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        a {
          sum: 3\%;
          ratio: 2;
          compatible: false;
          same: true;
          percent: 3%;
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });
});
