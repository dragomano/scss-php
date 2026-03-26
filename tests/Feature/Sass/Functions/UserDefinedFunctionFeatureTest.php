<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\FunctionReturnValueException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;

describe('User Defined Functions Feature', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('compiles real scss with local @function', function () {
        $scss = <<<'SCSS'
        @function fluid($base, $ratio: 1.5) {
          @return $base * $ratio;
        }

        .title {
          font-size: fluid(16px);
          line-height: fluid($base: 12px, $ratio: 2);
        }
        SCSS;

        $css = $this->compiler->compileString($scss);

        $expected = /** @lang text */ <<<'CSS'
        .title {
          font-size: 24px;
          line-height: 24px;
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('compiles real scss where @function calls sass:list', function () {
        $scss = <<<'SCSS'
        @use "sass:list";

        @function second-item($values) {
          @return list.nth($values, 2);
        }

        .card {
          token: second-item(primary secondary tertiary);
        }
        SCSS;

        $css = $this->compiler->compileString($scss);

        $expected = /** @lang text */ <<<'CSS'
        .card {
          token: secondary;
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('throws when required argument is missing', function () {
        expect(fn() => $this->compiler->compileString(<<<'SCSS'
            @function greet($name) {
              @return $name;
            }
            .x { content: greet(); }
        SCSS))->toThrow(MissingFunctionArgumentsException::class);
    });

    it('throws when function has no @return statement', function () {
        expect(fn() => $this->compiler->compileString(<<<'SCSS'
            @function no-return() {
              $x: 1;
            }
            .x { width: no-return(); }
        SCSS))->toThrow(FunctionReturnValueException::class);
    });

    it('uses default argument values and named arguments in compiled scss', function () {
        $scss = <<<'SCSS'
        @function scale($n, $factor: 2) {
          @return $n * $factor;
        }

        @function subtract($a, $b) {
          @return $a - $b;
        }

        .x {
          width: scale(3px);
          margin-left: subtract($b: 3px, $a: 10px);
        }
        SCSS;

        $css = $this->compiler->compileString($scss);

        $expected = /** @lang text */ <<<'CSS'
        .x {
          width: 6px;
          margin-left: 7px;
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('supports flow control and rest arguments inside user functions', function () {
        $scss = <<<'SCSS'
        @function clamp-val($n) {
          @if $n > 100 {
            @return 100;
          }

          @return $n;
        }

        @function first($args...) {
          @return nth($args, 1);
        }

        @function sum($list...) {
          $total: 0;

          @each $n in $list {
            $total: $total + $n;
          }

          @return $total;
        }

        .x {
          width: clamp-val(200px);
          color: first(red, green, blue);
          padding: sum(1, 2, 3) * 1px;
        }
        SCSS;

        $css = $this->compiler->compileString($scss);

        $expected = /** @lang text */ <<<'CSS'
        .x {
          width: 100;
          color: red;
          padding: 6px;
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });
});
