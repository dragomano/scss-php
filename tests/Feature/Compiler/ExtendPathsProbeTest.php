<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Compiler extend paths', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('extends compound anchors into descendant chains', function () {
        $source = <<<'SCSS'
        .a {
          color: red;
        }

        .b {
          @extend .a;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .a, .b {
          color: red;
        }
        CSS;

        expect($this->compiler->compileString($source))->toEqualCss($expected);
    });
});
