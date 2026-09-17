<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('CSS serialization regressions', function () {
    it('preserves interpolated media operators', function (string $condition, string $expected) {
        $source = '@media (' . $condition . ') { x { y: z; } }';
        $css = "@media $expected {\n  x {\n    y: z;\n  }\n}";

        expect((new Compiler())->compileString($source))->toEqualCss($css);
    })->with([
        ['NoT (a)', 'not (a)'],
        ['#{"not (a)"}', 'not (a)'],
        ['#{"NoT (a)"}', '(NoT (a))'],
        ['#{"(a) AnD (b)"}', '((a) AnD (b))'],
        ['#{"(a) oR (b)"}', '((a) oR (b))'],
    ]);

    it('separates identifiers but not numbers after unicode wildcards', function () {
        $source = 'a { ident: U+A?BCDE; minus: U+A?-BCDE; number: U+A?-1234; }';
        $expected = /** @lang text */ <<<'CSS'
        a {
          ident: U+A? BCDE;
          minus: U+A? -BCDE;
          number: U+A?-1234;
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });

    it('collapses empty lines and reindents custom properties', function () {
        $source = <<<'SCSS'
        a {
                 --deep: {
                   foo: bar;

                   baz: bang;
                 };
          --below:
            foo
         bar
           baz;
        }
        SCSS;
        $expected = /** @lang text */ <<<'CSS'
        a {
          --deep: {
            foo: bar;
            baz: bang;
          };
          --below:
             foo
          bar
            baz;
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });
});
