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
              box-shadow: 0 2px 5px rgba(0, 0, 0, 0.3);
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
    });
});
