<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Syntax;
use Tests\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('evaluates namespaced sass:list functions', function () {
            $source = <<<'SCSS'
            @use "sass:list";
            $comma-list: 10px, 20px;
            .test {
              len: list.length(10px 20px 30px);
              last: list.nth(a b c, -1);
              appended: list.append(10px 20px, 30px, $separator: comma);
              sep: list.separator($comma-list);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              len: 3;
              last: c;
              appended: 10px, 20px, 30px;
              sep: comma;
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

        it('evaluates global sass:list aliases without namespace', function () {
            $source = <<<'SCSS'
            $comma-list: 10px, 20px;
            .test {
              len: length(10px 20px 30px);
              second: nth(a b c, 2);
              joined: join(10px 20px, 30px 40px, $separator: comma);
              separator: list-separator($comma-list);
              bracketed: is-bracketed(a b);
              idx: index(a b c, b);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              len: 3;
              second: b;
              joined: 10px, 20px, 30px, 40px;
              separator: comma;
              bracketed: false;
              idx: 2;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles mixins', function () {
            $source = <<<'SCSS'
            @mixin button-style($color) { color: $color; }
            .test { @include button-style(red); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles bRadius mixin with chained and/or conditions', function () {
            $source = <<<'SCSS'
            @mixin bRadius($topLeft: 6px, $topRight: null, $bottomRight: null, $bottomLeft: null) {
              @if $topRight == null and $bottomRight == null and $bottomLeft == null and $topLeft != null and $topLeft >= 0 {
                border-radius: $topLeft;
              } @else {
                $tl: if($topLeft == null or $topLeft < 0, 0, $topLeft);
                $tr: if($topRight == null or $topRight < 0, 0, $topRight);
                $br: if($bottomRight == null or $bottomRight < 0, 0, $bottomRight);
                $bl: if($bottomLeft == null or $bottomLeft < 0, 0, $bottomLeft);

                @if $tl == $tr and $tr == $br and $br == $bl {
                  border-radius: $tl;
                } @else if $tl == $br and $tr == $bl {
                  border-radius: $tl $tr;
                } @else if $tr == $bl {
                  border-radius: $tl $tr $br;
                } @else {
                  border-radius: $tl $tr $br $bl;
                }
              }
            }

            .default { @include bRadius(); }
            .single { @include bRadius(8px); }
            .pair { @include bRadius(10px, 20px, 10px, 20px); }
            .triple { @include bRadius(1px, 2px, 3px, 2px); }
            .quad { @include bRadius(8px, 4px, 2px, 1px); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .default {
              border-radius: 6px;
            }
            .single {
              border-radius: 8px;
            }
            .pair {
              border-radius: 10px 20px;
            }
            .triple {
              border-radius: 1px 2px 3px;
            }
            .quad {
              border-radius: 8px 4px 2px 1px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('bubbles keyframes from mixin content and keeps interpolated names without extra spaces', function () {
            $source = <<<'SCSS'
            @mixin inline-animation($duration) {
              $name: inline-#{unique-id()};

              @keyframes #{$name} {
                @content;
              }

              animation-name: $name;
              animation-duration: $duration;
              animation-iteration-count: infinite;
            }

            .pulse {
              @include inline-animation(2s) {
                from { background-color: yellow }
                to { background-color: red }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @keyframes inline-u1 {
              from {
                background-color: yellow;
              }

              to {
                background-color: red;
              }
            }
            .pulse {
              animation-name: inline-u1;
              animation-duration: 2s;
              animation-iteration-count: infinite;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps @content lexical scope and does not expose mixin-local variables', function () {
            $source = <<<'SCSS'
            @mixin hover {
              $inner-border-width: 10px;

              &:not([disabled]):hover {
                outline: $inner-border-width solid red;
                @content;
              }
            }

            .button {
              border: 1px solid black;
              $outer-border-width: 2px;

              @include hover {
                border-width: $outer-border-width;
                border-top-width: $inner-border-width;
              }
            }
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $inner-border-width');
        });

        it('passes @content arguments into @include using parameters', function () {
            $source = <<<'SCSS'
            @mixin media($types...) {
              @each $type in $types {
                @media #{$type} {
                  @content($type);
                }
              }
            }

            @include media(screen, print) using ($type) {
              h1 {
                font-size: 40px;

                @if $type == print {
                  font-family: Calluna;
                }
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media screen {
              h1 {
                font-size: 40px;
              }
            }
            @media print {
              h1 {
                font-size: 40px;
                font-family: Calluna;
              }
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('bubbles @content output through a mixin-created media query', function () {
            $source = <<<'SCSS'
            @mixin media($condition...) {
              @media (#{$condition}) {
                @content;
              }
            }

            .content-media-bug {
              @include media("min-width: 600px") {
                width: 50%;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (min-width: 600px) {
              .content-media-bug {
                width: 50%;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps mixin output grouped when a mixin is included inside @media', function () {
            $source = <<<'SCSS'
            @mixin bordered {
              border: 1px solid black;
            }

            .media-grouping-bug {
              @media (min-width: 768px) {
                @include bordered;
                color: blue;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @media (min-width: 768px) {
              .media-grouping-bug {
                border: 1px solid black;
                color: blue;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('bubbles a mixin-created media query after base declarations', function () {
            $source = <<<'SCSS'
            @mixin desktop {
              @media (min-width: 1024px) {
                @content;
              }
            }

            .button {
              width: 100%;

              @include desktop {
                width: auto;
                background: blue;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .button {
              width: 100%;
            }
            @media (min-width: 1024px) {
              .button {
                width: auto;
                background: blue;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps declarations outside and inside mixin-created media blocks separated correctly', function () {
            $source = <<<'SCSS'
            @mixin test {
              border: 1px solid black;
              @media (min-width: 600px) {
                color: red;
              }
            }

            .rule {
              @include test;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .rule {
              border: 1px solid black;
            }
            @media (min-width: 600px) {
              .rule {
                color: red;
              }
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps only the last duplicate declaration after @include without extra blank lines', function () {
            $source = <<<'SCSS'
            @mixin button-style($color) {
              background-color: $color;
              border-radius: 7px;
              &:hover {
                background-color: blue;
              }
            }

            .test {
              @include button-style(red);
              border-radius: 3px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              background-color: red;
              border-radius: 3px;
            }
            .test:hover {
              background-color: blue;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps vendor fallback declarations with the same property after @include', function () {
            $source = <<<'SCSS'
            @mixin flexbox() {
              display: -webkit-box;
              display: -moz-box;
              display: -ms-flexbox;
              display: -webkit-flex;
              display: flex;
            }

            @mixin flex($values) {
              -webkit-box-flex: $values;
              -moz-box-flex: $values;
              -webkit-flex: $values;
              -ms-flex: $values;
              flex: $values;
            }

            @mixin order($val) {
              -webkit-box-ordinal-group: $val;
              -moz-box-ordinal-group: $val;
              -ms-flex-order: $val;
              -webkit-order: $val;
              order: $val;
            }

            .wrapper {
              @include flexbox();
            }

            .item {
              @include flex(1 200px);
              @include order(2);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .wrapper {
              display: -webkit-box;
              display: -moz-box;
              display: -ms-flexbox;
              display: -webkit-flex;
              display: flex;
            }
            .item {
              -webkit-box-flex: 1 200px;
              -moz-box-flex: 1 200px;
              -webkit-flex: 1 200px;
              -ms-flex: 1 200px;
              flex: 1 200px;
              -webkit-box-ordinal-group: 2;
              -moz-box-ordinal-group: 2;
              -ms-flex-order: 2;
              -webkit-order: 2;
              order: 2;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps include-generated nested selector order before following nested rules', function () {
            $source = <<<'SCSS'
            @mixin variations($color) {
              .light { color: lighten($color, 20%); }
              .dark { color: darken($color, 20%); }
            }

            .class-0 {
              @include variations(#3399ff);

              &.nested-1 {
                color: red;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .class-0 .light {
              color: #9cf;
            }
            .class-0 .dark {
              color: #06c;
            }
            .class-0.nested-1 {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
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

        it('supports spread arguments for sass functions', function () {
            $source = <<<'SCSS'
            @use "sass:list";
            $lists: (10px 12px), (solid dashed), (red blue);
            .test {
              value: list.zip($lists...);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: 10px solid red, 12px dashed blue;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports spread operator for arbitrary arguments', function () {
            $source = <<<'SCSS'
            $widths: 50px, 30px, 100px;
            .micro {
              width: min($widths...);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .micro {
              width: 30px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
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

        it('compiles variable declarations and references', function () {
            $source = <<<'SCSS'
            $primary-color: #333;
            .test { color: $primary-color; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: #333;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('treats hyphens and underscores as equivalent in variable names', function () {
            $source = <<<'SCSS'
            $font-size: 16px;
            .test {
              a: $font_size;
              b: $font-size;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              a: 16px;
              b: 16px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('allows reassignment across underscore and hyphen spellings for one variable', function () {
            $source = <<<'SCSS'
            $font_size: 12px;
            $font-size: 20px;
            .test {
              value: $font_size;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: 20px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('treats hyphens and underscores as equivalent in mixin names', function () {
            $source = <<<'SCSS'
            @mixin reset-list() {
              margin: 0;
              padding: 0;
            }

            .test {
              @include reset_list();
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              margin: 0;
              padding: 0;
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

        it('supports !default and !global variable modifiers', function () {
            $source = <<<'SCSS'
            $brand: null;
            $brand: blue !default;
            $size: 1px;

            .box {
              $size: 2px !global;
              color: $brand;
              width: $size;
            }

            .after {
              width: $size;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .box {
              color: blue;
              width: 2px;
            }
            .after {
              width: 2px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps local variable assignments scoped to the current rule', function () {
            $source = <<<'SCSS'
            $variable: global value;

            .content {
              $variable: local value;
              value: $variable;
            }

            .sidebar {
              value: $variable;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .content {
              value: local value;
            }
            .sidebar {
              value: global value;
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
});
