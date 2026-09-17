<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Compiler extend weave paths', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('extends compound anchors through descendant chains', function () {
        $source = <<<'SCSS'
        a .b {
          color: red;
        }

        c {
          @extend b;
        }
        SCSS;

        expect($this->compiler->compileString($source))->toEqualCss(
            /** @lang text */
            <<<'CSS'
            a .b {
              color: red;
            }
            CSS,
        );
    });
});
