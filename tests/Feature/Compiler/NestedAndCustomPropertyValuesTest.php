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
              --bg-image: url('../images/background.jpg');
              --icon-check: url('data:image/svg+xml;utf8,<svg>...</svg>');
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

        it('preserves variable references in css custom function result', function () {
            $source = <<<'SCSS'
            @function --double($x) {
              result: $x * 2;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @function --double($x) {
              result: $x * 2;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates interpolated property name in css custom function result', function () {
            $source = <<<'SCSS'
            @function --a() {
              #{result}: 1 + 1;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @function --a() {
              result: 2;
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
