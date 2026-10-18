<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('fuzzy-rounds legacy RGB channel accessors to integers', function () {
        $source = <<<'SCSS'
        .checks {
          mixed-half:  red(mix(#0d6efd, #f8f9fa, 50%));
          mixed-high:  red(mix(#0d6efd, #f8f9fa, 60%));
          mixed-alpha: red(mix(rgba(0, 0, 0, .5), #fff, 50%));
          literal:     red(rgb(130.5, 20.5, 0.5));
          zero:        blue(rgb(0, 0, 0));
        }

        .kept-raw {
          hue: hue(hsl(20.5, 50.5%, 50.5%));
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        .checks {
          mixed-half: 131;
          mixed-high: 107;
          mixed-alpha: 191;
          literal: 131;
          zero: 0;
        }

        .kept-raw {
          hue: 20.5deg;
        }
        CSS;

        expect($this->compiler->compileString($source))->toEqualCss($expected);
    });
});
