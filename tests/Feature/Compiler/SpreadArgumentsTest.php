<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
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
});
