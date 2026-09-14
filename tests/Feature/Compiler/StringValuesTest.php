<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Syntax;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

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

        it('keeps quoted braces and comments inside string interpolation spans', function () {
            $source = <<<'SCSS'
            .interpolation-span {
              content-a: "#{ "}" }";
              content-b: "#{ a /* } */ b }";
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .interpolation-span {
              content-a: "}";
              content-b: "a b";
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
    });
});
