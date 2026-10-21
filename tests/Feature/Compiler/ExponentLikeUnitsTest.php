<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Style;

describe('Exponent-like units', function () {
    it('matches Dart Sass serialization in expanded and compressed output', function () {
        $source = <<<'SCSS'
        @use "sass:math";
        @use "sass:meta";
        a {
          lower: 1\65 1;
          upper: 1\45 1;
          after-exponent: 1e1e1;
          longer: 1\65 12foo;
          negative: -2\65 1;
          decimal: 0.5\65 1;
          unchanged: 1em;
          lone: 1e;
          interpolated: #{1\65 1};
          quoted: "#{1\65 1}";
          inspect: meta.inspect(1\65 1);
          upper-unit: math.unit(1\45 1);
          sum: 1\65 1 + 2\65 1;
          product: 2\65 1 * 3px;
          infinite: calc(infinity * 1\65 1);
        }
        SCSS;

        $expanded = /** @lang text */ <<<'CSS'
        a {
          lower: 1\65 1;
          upper: 1\65 1;
          after-exponent: 10\65 1;
          longer: 1\65 12foo;
          negative: -2\65 1;
          decimal: 0.5\65 1;
          unchanged: 1em;
          lone: 1e;
          interpolated: 1\65 1;
          quoted: "1\\65 1";
          inspect: 1\65 1;
          upper-unit: "E1";
          sum: 3\65 1;
          product: calc(6e1 * 1px);
          infinite: calc(infinity * 1e1);
        }
        CSS;

        $compressed = /** @lang text */ <<<'CSS'
        a{lower:1\65 1;upper:1\65 1;after-exponent:10\65 1;longer:1\65 12foo;negative:-2\65 1;decimal:.5\65 1;unchanged:1em;lone:1e;interpolated:1\65 1;quoted:"1\\65 1";inspect:1\65 1;upper-unit:"E1";sum:3\65 1;product:calc(6e1*1px);infinite:calc(infinity*1e1)}
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expanded)
            ->and((new Compiler(new CompilerOptions(style: Style::COMPRESSED)))->compileString($source))->toEqualCss($compressed);
    });
});
