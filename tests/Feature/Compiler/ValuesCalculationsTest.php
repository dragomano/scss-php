<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Exceptions\UndefinedOperationException;
use Bugo\SCSS\Syntax;
use Tests\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('compiles color values', function () {
            $source = <<<'SCSS'
            .colors { hex3: #f00; hex6: #f00; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .colors {
              hex3: #f00;
              hex6: #f00;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles number values with units', function () {
            $source = <<<'SCSS'
            .numbers { width: 100px; opacity: 0.5; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .numbers {
              width: 100px;
              opacity: .5;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves CSS filter functions in filter property', function (string $source, string $expected) {
            expect($this->compiler->compileString($source))->toEqualCss($expected);
        })->with([
            ['.test { filter: hue-rotate(177deg) saturate(109%); }', ".test {\n  filter: hue-rotate(177deg) saturate(109%);\n}"],
            ['.test { filter: blur(5px) brightness(1.2) contrast(150%); }', ".test {\n  filter: blur(5px) brightness(1.2) contrast(150%);\n}"],
            ['.test { filter: saturate(150%); }', ".test {\n  filter: saturate(150%);\n}"],
            ['.test { filter: sepia(0.8) saturate(120%) hue-rotate(45deg); }', ".test {\n  filter: sepia(.8) saturate(120%) hue-rotate(45deg);\n}"],
        ]);

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
              grid-row: 2 / 4;
              grid-column: 1 / 4;
              font: 16px/1.4 Arial;
              margin: .5;
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
              width: 6 / 2px;
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
              width: 6px / 2;
              height: 3px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('compiles hsl function', function () {
            $source = <<<'SCSS'
            .test { color: hsl(120, 100%, 50%); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: hsl(120, 100%, 50%);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles rgba function', function () {
            $source = <<<'SCSS'
            .test { box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              box-shadow: 0 2px 5px rgba(0, 0, 0, .3);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('omits null items from generated css lists', function () {
            $source = <<<'SCSS'
            $fonts: ("serif": "Helvetica Neue", "monospace": "Consolas");

            h3 {
              font: 18px bold map-get($fonts, "sans");
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            h3 {
              font: 18px bold;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps !important in declarations', function () {
            $source = <<<'SCSS'
            .test { color: red !important; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: red !important;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves non-color css declarations like order and explicit !important values', function () {
            $source = <<<'SCSS'
            .card-header {
              overflow: hidden;
              z-index: 0;
              position: relative;
              order: 1;
            }

            .article {
              grid-column: span 1 !important;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .card-header {
              overflow: hidden;
              z-index: 0;
              position: relative;
              order: 1;
            }
            .article {
              grid-column: span 1 !important;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('handles @font-face url() forms in sass syntax', function () {
            $source = <<<'SASS'
            $roboto-font-path: "../fonts/roboto";

            @font-face {
              src: url("#{$roboto-font-path}/Roboto-Thin.woff2") format("woff2");
              font-family: "Roboto";
              font-weight: 100;
            }

            @font-face {
              src: url($roboto-font-path + "/Roboto-Light.woff2") format("woff2");
              font-family: "Roboto";
              font-weight: 300;
            }

            @font-face {
              src: url(#{$roboto-font-path}/Roboto-Regular.woff2) format("woff2");
              font-family: "Roboto";
              font-weight: 400;
            }
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            @font-face {
              src: url("../fonts/roboto/Roboto-Thin.woff2") format("woff2");
              font-family: "Roboto";
              font-weight: 100;
            }
            @font-face {
              src: url("../fonts/roboto/Roboto-Light.woff2") format("woff2");
              font-family: "Roboto";
              font-weight: 300;
            }
            @font-face {
              src: url(../fonts/roboto/Roboto-Regular.woff2) format("woff2");
              font-family: "Roboto";
              font-weight: 400;
            }
            CSS;

            $css = $this->compiler->compileString($source, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('preserves double slash in @namespace url()', function () {
            $source = <<<'SCSS'
            @namespace svg url(http://www.w3.org/2000/svg);
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @namespace svg url(http://www.w3.org/2000/svg);
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves hash interpolation in function arguments', function () {
            $source = <<<'SCSS'
            $logo-element: logo-bg;

            .logo {
              background: element(##{$logo-element});
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .logo {
              background: element(#logo-bg);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps interpolated calc arguments and nested string interpolation in declarations', function () {
            $source = <<<'SCSS'
            $size: 10px;

            .interpolation-bug {
              width: calc(#{$size} + #{5px});
              margin: #{"top-" + #{"left"}};
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .interpolation-bug {
              width: calc(10px + 5px);
              margin: top-left;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('concatenates quoted string results without duplicating quotes', function () {
            $source = <<<'SCSS'
            @use "sass:string";

            .test {
              content: string.slice("hello", 1, 3) + " world";
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              content: "hel world";
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps calc() results after interpolation when output remains a calculation', function () {
            $source = <<<'SCSS'
            $width: 100px;
            $min-padding: min(10px, 2vw);

            body {
              width: calc(#{$width} + 20px);
              height: calc(100% * 0.5);
            }

            div {
              width: calc($min-padding * 2);
              height: calc(20px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            body {
              width: calc(100px + 20px);
              height: 50%;
            }
            div {
              width: calc(min(10px, 2vw) * 2);
              height: 20px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('formats scientific notation numbers in declarations', function () {
            $scss = <<<'SASS'
            .test {
              wide: 5.2e3;
              tiny: 6e-2;
            }
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              wide: 5200;
              tiny: .06;
            }
            CSS;

            $css = $this->compiler->compileString($scss, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('keeps calc wrapper for compound units in declarations', function () {
            $scss = <<<'SASS'
            @use 'sass:math';

            $degrees-per-second: math.div(20deg, 1s);

            .test {
              area: 4px * 6px;
              velocity: math.div(5px, 2s);
              complex: 5px * math.div(math.div(30deg, 2s), 24em);
              ratio: $degrees-per-second;
              inverse: math.div(1, $degrees-per-second);
            }
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              area: calc(24px * 1px);
              velocity: calc(2.5px / 1s);
              complex: calc(3.125px * 1deg / 1s / 1em);
              ratio: calc(20deg / 1s);
              inverse: calc(.05s / 1deg);
            }
            CSS;

            $css = $this->compiler->compileString($scss, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('throws for incompatible units in arithmetic expressions', function () {
            $scss = <<<'SASS'
            .test {
              bad: 1in + 1s;
            }
            SASS;

            expect(fn() => $this->compiler->compileString($scss, Syntax::SASS))
                ->toThrow(IncompatibleUnitsException::class, '1in and 1s have incompatible units.');
        });

        it('throws for sassscript arithmetic with calculation values', function () {
            $scss = <<<'SASS'
            $width: calc(100% + 10px);

            .test {
              bad: $width * 2;
            }
            SASS;

            expect(fn() => $this->compiler->compileString($scss, Syntax::SASS))
                ->toThrow(UndefinedOperationException::class, 'Undefined operation "calc(100% + 10px) * 2".');
        });

        it('evaluates modulo inside legacy max() function calls', function () {
            $scss = <<<'SCSS'
            $padding: 12px;
            .post {
              padding-left: max($padding, env(safe-area-inset-left));
              padding-right: max($padding, env(safe-area-inset-right));
            }
            .sidebar {
              padding-left: max($padding % 10, 20px);
              padding-right: max($padding % 10, 20px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .post {
              padding-left: max(12px, env(safe-area-inset-left));
              padding-right: max(12px, env(safe-area-inset-right));
            }
            .sidebar {
              padding-left: 20px;
              padding-right: 20px;
            }
            CSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates modulo inside legacy min abs and round function calls', function () {
            $scss = <<<'SCSS'
            $padding: 12px;
            .test {
              min-value: min($padding % 10, 5px);
              abs-value: abs(-$padding % 10);
              round-value: round($padding % 10);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              min-value: 2px;
              abs-value: 2px;
              round-value: 2px;
            }
            CSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates arithmetic segment after identifier token in declaration values', function () {
            $scss = <<<'SASS'
            @use 'sass:math';

            $transition-speed: math.div(1s, 50px);

            .slider {
              transition: left (120px - 10px) * $transition-speed;
            }
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .slider {
              transition: left 2.2s;
            }
            CSS;

            $css = $this->compiler->compileString($scss, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('preserves backslash escapes in quoted strings', function () {
            $source = <<<'SASS'
            $icons: ("eye": "\f112")

            @each $name, $glyph in $icons
              .icon-#{$name}:before
                content: $glyph
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .icon-eye:before {
              content: "\f112";
            }
            CSS;

            $css = $this->compiler->compileString($source, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('decodes astral unicode escapes in quoted strings and emits charset', function () {
            $source = <<<'SCSS'
            @counter-style thumbs {
              system: cyclic;
              symbols: "\1F44D";
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @charset "UTF-8";
            @counter-style thumbs {
              system: cyclic;
              symbols: "👍";
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('concatenates strings with plus operator in user-defined functions', function () {
            $source = <<<'SCSS'
            @use "sass:string";

            @function str-insert($string, $insert, $index) {
              $before: string.slice($string, 0, $index);
              $after: string.slice($string, $index);
              @return $before + $insert + $after;
            }

            .test {
              value: str-insert('test', '22', 2);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: "te22est";
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('concatenates identifier strings with minus operator', function () {
            $source = <<<'SCSS'
            .test {
              a: sans - serif;
              b: sans- + serif;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              a: sans-serif;
              b: sans-serif;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('concatenates quoted string with number using plus operator', function () {
            $source = <<<'SCSS'
            .test {
              a: "elapsed: " + 10s;
              b: "hello" + 42px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              a: "elapsed: 10s";
              b: "hello42px";
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('collapses number plus unit suffix into a dimension', function () {
            $source = <<<'SCSS'
            $raw-size: 42;

            .debug-suffix-bug {
              width: $raw-size + px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .debug-suffix-bug {
              width: 42px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps unary prefix slash and minus before identifiers', function () {
            $source = <<<'SCSS'
            .test {
              a: - moz;
              b: / 15px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              a: -moz;
              b: /15px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('compiles nested properties declared as property block', function () {
            $source = <<<'SCSS'
            .enlarge {
              font-size: 14px;
              transition: {
                property: font-size;
                duration: 4s;
                delay: 2s;
              }

              &:hover { font-size: 36px; }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .enlarge {
              font-size: 14px;
              transition-property: font-size;
              transition-duration: 4s;
              transition-delay: 2s;
            }
            .enlarge:hover {
              font-size: 36px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles nested properties declared as property value block', function () {
            $source = <<<'SCSS'
            .info-page {
              margin: auto {
                bottom: 10px;
                top: 2px;
              }
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .info-page {
              margin: auto;
              margin-bottom: 10px;
              margin-top: 2px;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('omits declarations when inline if() resolves to null', function () {
            $source = <<<'SCSS'
            $rounded-corners: false;

            .button {
              border: 1px solid black;
              border-radius: if(sass($rounded-corners): 5px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .button {
              border: 1px solid black;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('keeps custom property values as raw css while interpolating #{} fragments', function () {
            $source = <<<'SCSS'
            $primary: #81899b;
            $accent: #302e24;
            $warn: #dfa612;

            :root {
              --primary: #{$primary};
              --accent: #{$accent};
              --warn: #{$warn};
              --consumed-by-js: $primary;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            :root {
              --primary: #81899b;
              --accent: #302e24;
              --warn: #dfa612;
              --consumed-by-js: $primary;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves quoted strings and escapes in custom property values', function () {
            $source = <<<'SCSS'
            :root {
              $roboto-variant: "Mono";
              --debug-1: "\"";
              --debug-2: "\.widget";
              --debug-3: "\a";
              --debug-4: "line1\aline2";
              --debug-7: "C:\\Program Files";
              --debug-9: "Roboto #{$roboto-variant}";
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            :root {
              --debug-1: "\"";
              --debug-2: "\.widget";
              --debug-3: "\a";
              --debug-4: "line1\aline2";
              --debug-7: "C:\\Program Files";
              --debug-9: "Roboto Mono";
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves transform functions in declarations', function (string $source, string $expected) {
            expect($this->compiler->compileString($source))->toEqualCss($expected);
        })->with([
            ['.test { transform: translate(10px, 20px); }', ".test {\n  transform: translate(10px, 20px);\n}"],
            ['.test { transform: rotate(45deg); }', ".test {\n  transform: rotate(45deg);\n}"],
            ['.test { transform: scale(1.5); }', ".test {\n  transform: scale(1.5);\n}"],
            ['.test { transform: skew(30deg, 20deg); }', ".test {\n  transform: skew(30deg, 20deg);\n}"],
        ]);

        it('preserves CSS custom property values with urls and var()', function () {
            $source = <<<'SCSS'
            :root {
              --bg-image: url('../images/background.jpg');
              --icon-check: url('data:image/svg+xml;utf8,<svg>...</svg>');
            }

            .using-css-vars {
              background-image: var(--bg-image);
            }

            .checkbox::before {
              content: var(--icon-check);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            :root {
              --bg-image: url("../images/background.jpg");
              --icon-check: url("data:image/svg+xml;utf8,<svg>...</svg>");
            }
            .using-css-vars {
              background-image: var(--bg-image);
            }
            .checkbox::before {
              content: var(--icon-check);
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('preserves data uri content inside quoted url() arguments', function () {
            $source = <<<'SCSS'
            .data-uri-bug {
              background: url("./bg.jpg");
              background-image: url("https://cdn.example.com/banner.png");
              list-style-image: url("../icons/bullet.png");
              content: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"/>');
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .data-uri-bug {
              background: url("./bg.jpg");
              background-image: url("https://cdn.example.com/banner.png");
              list-style-image: url("../icons/bullet.png");
              content: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg"/>');
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('evaluates function expressions inside custom property interpolation', function () {
            $source = <<<'SCSS'
            @use "sass:meta";

            $font-family-sans-serif: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto;
            $font-family-monospace: SFMono-Regular, Menlo, Monaco, Consolas;

            :root {
              --font-family-sans-serif: #{meta.inspect($font-family-sans-serif)};
              --font-family-monospace: #{meta.inspect($font-family-monospace)};
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            :root {
              --font-family-sans-serif: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto;
              --font-family-monospace: SFMono-Regular, Menlo, Monaco, Consolas;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('treats @function with css custom function name as plain css at-rule block', function () {
            $source = <<<'SCSS'
            $highlight: #ddf;

            @function --highlight() {
              result: var(--highlight, #{$highlight});
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @function --highlight() {
              result: var(--highlight, #ddf);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves multi-word var() fallback', function () {
            $source = <<<'SCSS'
            .a { border: var(--b, 1px solid red); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              border: var(--b, 1px solid red);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('preserves var() fallback with comma', function () {
            $source = <<<'SCSS'
            .a { font: var(--f, Arial, sans-serif); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a {
              font: var(--f, Arial, sans-serif);
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });
    });
});
