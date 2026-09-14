<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\Support\ArrayLogger;

describe('Sass Color Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('color.to-gamut()', function () {
        it('compiles color.to-gamut() in original oklch space for local-minde', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-gamut { value: color.to-gamut(oklch(60% 70% 20deg), $space: rgb, $method: local-minde); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-gamut {
              value: oklch(61.2058837805% 0.2466052582 22.0773321712deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-gamut() unchanged for rgb color already in gamut', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-gamut { value: color.to-gamut(#036, $method: local-minde); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-gamut {
              value: #036;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('compiles color.to-gamut() in original oklch space for clip', function () {
            $scss = <<<'SCSS'
            @use "sass:color";
            .color-to-gamut { value: color.to-gamut(oklch(60% 70% 20deg), $space: rgb, $method: clip); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .color-to-gamut {
              value: oklch(62.5026608983% 0.2528579733 24.1000460045deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });
});
