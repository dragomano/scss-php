<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Compiler', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    describe('compileString()', function () {
        it('preserves multiline comment formatting at the top of the file', function () {
            $source = <<<'SCSS'
            /*
            This is a multiline comment
                that spans multiple lines
            */
            body {
              color: red;
            }
            SCSS;

            $expected = <<<'CSS'
            /*
            This is a multiline comment
                that spans multiple lines
            */
            body {
              color: red;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps selector list parts around an inline silent comment', function () {
            $source = <<<'SCSS'
            button,
            [type="button"], // 1
            [type="reset"],
            [type="submit"] {
              -webkit-appearance: button;
            }
            SCSS;

            $expected = <<<'CSS'
            button,
            [type=button],
            [type=reset],
            [type=submit] {
              -webkit-appearance: button;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });
    });
});
