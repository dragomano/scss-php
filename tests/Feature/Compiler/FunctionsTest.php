<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Syntax;
use Tests\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    it('evaluates user-defined functions', function () {
        $source = <<<'SCSS'
        @function double($value) { @return $value * 2; }
        .test { value: double(3); }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          value: 6;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('evaluates user-defined functions with default and named arguments', function () {
        $source = <<<'SCSS'
        @function scale($value, $factor: 2) { @return $value * $factor; }
        .test {
          default: scale(3);
          named: scale($value: 4, $factor: 3);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          default: 6;
          named: 12;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('evaluates user-defined functions with @if branches', function () {
        $source = <<<'SCSS'
        @function pick($value) {
          @if $value > 10 { @return large; }
          @return small;
        }
        .test {
          a: pick(11);
          b: pick(5);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          a: large;
          b: small;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('appends to an accumulated empty list as a space-separated list by default', function () {
        $source = <<<'SCSS'
        @use "sass:list";
        @use "sass:map";

        $prefixes-by-browser: ("firefox": moz, "safari": webkit, "ie": ms);

        @function prefixes-for-browsers($browsers) {
          $prefixes: ();

          @each $browser in $browsers {
            $prefixes: list.append($prefixes, map.get($prefixes-by-browser, $browser));
          }

          @return $prefixes;
        }

        .test {
          value: prefixes-for-browsers("firefox" "ie");
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          value: moz ms;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('supports rest arguments in user-defined functions', function () {
        $source = <<<'SCSS'
        @function count-values($args...) {
          @return length($args);
        }
        .test {
          value: count-values(1, 2, 3);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          value: 3;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('supports argument lists in @function with @each iteration', function () {
        $source = <<<'SCSS'
        @function sum($numbers...) {
          $sum: 0;
          @each $number in $numbers {
            $sum: $sum + $number;
          }
          @return $sum;
        }

        .micro {
          width: sum(50px, 30px, 100px);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .micro {
          width: 180px;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('supports @while loops inside user-defined functions', function () {
        $source = <<<'SASS'
        @use "sass:math"

        @function scale-below($value, $base, $ratio: 1.618)
          @while $value > $base
            $value: math.div($value, $ratio)
          @return $value

        sup
          font-size: scale-below(20px, 16px)
        SASS;

        $expected = /** @lang text */ <<<'CSS'
        sup {
          font-size: 12.3609394314px;
        }
        CSS;

        $css = $this->compiler->compileString($source, Syntax::SASS);

        expect($css)->toEqualCss($expected);
    });

    it('supports meta.keywords() for user rest arguments', function () {
        $source = <<<'SCSS'
        @use "sass:meta";
        @use "sass:map";

        @function pick-a($args...) {
          $kw: meta.keywords($args);
          @return map.get($kw, a);
        }

        .test {
          value: pick-a($a: 7, $b: 9);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          value: 7;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('treats hyphens and underscores as equivalent in user-defined function names', function () {
        $source = <<<'SCSS'
        @function double-value($n) {
          @return $n * 2;
        }

        .test {
          a: double_value(2);
          b: double-value(3);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          a: 4;
          b: 6;
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('treats hyphens and underscores as equivalent in built-in function names', function () {
        $source = <<<'SCSS'
        @use "sass:color";
        @use "sass:string";

        .test {
          a: scale_color(#6699cc, $lightness: 10%);
          b: scale-color(#6699cc, $lightness: 10%);
          c: string.to_upper_case("ab");
          d: string.to-upper-case("ab");
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .test {
          a: rgb(117.3, 163.2, 209.1);
          b: rgb(117.3, 163.2, 209.1);
          c: "AB";
          d: "AB";
        }
        CSS;

        $css = $this->compiler->compileString($source);

        expect($css)->toEqualCss($expected);
    });

    it('emits a deprecation warning when defining a function named url', function () {
        $source = <<<'SCSS'
        @function url() { @return "custom"; }
        .test { value: url(); }
        SCSS;

        $this->compiler->compileString($source);

        expect($this->logger->records)->toHaveCount(1)
            ->and($this->logger->records[0]['level'])->toBe('warning')
            ->and($this->logger->records[0]['message'])->toContain('"url"')
            ->and($this->logger->records[0]['message'])->toContain('Invalid function name');
    });

    it('emits a deprecation warning when defining a function named expression', function () {
        $source = <<<'SCSS'
        @function expression($v) { @return $v; }
        .test { value: expression(1px); }
        SCSS;

        $this->compiler->compileString($source);

        expect($this->logger->records)->toHaveCount(1)
            ->and($this->logger->records[0]['level'])->toBe('warning')
            ->and($this->logger->records[0]['message'])->toContain('"expression"')
            ->and($this->logger->records[0]['message'])->toContain('Invalid function name');
    });

    it('emits a deprecation warning when defining a function named element', function () {
        $source = <<<'SCSS'
        @function element($v) { @return $v; }
        .test { value: element(1px); }
        SCSS;

        $this->compiler->compileString($source);

        expect($this->logger->records)->toHaveCount(1)
            ->and($this->logger->records[0]['level'])->toBe('warning')
            ->and($this->logger->records[0]['message'])->toContain('"element"')
            ->and($this->logger->records[0]['message'])->toContain('Invalid function name');
    });

    it('emits a deprecation warning for reserved function names regardless of letter case', function () {
        $source = <<<'SCSS'
        @function URL() { @return "a"; }
        @function Expression($v) { @return $v; }
        @function ELEMENT($v) { @return $v; }
        SCSS;

        $this->compiler->compileString($source);

        expect($this->logger->records)->toHaveCount(3)
            ->and($this->logger->records[0]['level'])->toBe('warning')
            ->and($this->logger->records[0]['message'])->toContain('Invalid function name')
            ->and($this->logger->records[1]['level'])->toBe('warning')
            ->and($this->logger->records[1]['message'])->toContain('Invalid function name')
            ->and($this->logger->records[2]['level'])->toBe('warning')
            ->and($this->logger->records[2]['message'])->toContain('Invalid function name');
    });

    it('does not emit a deprecation warning for vendor-prefixed function names ending in -url', function () {
        $source = <<<'SCSS'
        @function -webkit-url($v) { @return $v; }
        .test { value: -webkit-url(1px); }
        SCSS;

        $this->compiler->compileString($source);

        expect($this->logger->records)->toBeEmpty();
    });

    it('does not emit a deprecation warning for vendor-prefixed function names ending in -expression', function () {
        $source = <<<'SCSS'
        @function -ms-expression($v) { @return $v; }
        .test { value: -ms-expression(1px); }
        SCSS;

        $this->compiler->compileString($source);

        expect($this->logger->records)->toBeEmpty();
    });

    it('rejects function declarations whose names begin with double hyphens', function () {
        $source = <<<'SCSS'
        @function --tool() {
          @return 10px;
        }

        .test {
          width: --tool();
        }
        SCSS;

        expect(fn() => $this->compiler->compileString($source))
            ->toThrow(SassErrorException::class, 'This at-rule is not allowed here.');
    });

    it('does not emit a deprecation warning for unrelated function names', function () {
        $source = <<<'SCSS'
        @function my-url-helper($v) { @return $v; }
        @function element-of($v) { @return $v; }
        .test {
          a: my-url-helper(1px);
          b: element-of(2px);
        }
        SCSS;

        $this->compiler->compileString($source);

        expect($this->logger->records)->toBeEmpty();
    });
});
