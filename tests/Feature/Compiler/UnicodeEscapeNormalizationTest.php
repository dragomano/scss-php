<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Unicode escape normalization', function () {
    it('replaces invalid escapes in identifiers hashes and interpolations', function () {
        $source = <<<'SCSS'
        $value: \0xyz;
        a {
          literal: \0xyz;
          variable: $value;
          interpolated: #{\0xyz};
          quoted: "#{\0xyz}";
          before-interpolation: \0#{x};
          after-interpolation: #{x}\0;
          out-of-range: \110000;
          hashed: #f00000\9\0;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @charset "UTF-8";
        a {
          literal: �xyz;
          variable: �xyz;
          interpolated: �xyz;
          quoted: "�xyz";
          before-interpolation: �x;
          after-interpolation: x�;
          out-of-range: �;
          hashed: #f00000\9 �;
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });

    it('preserves standalone null and valid control escapes', function () {
        $source = <<<'SCSS'
        a {
          null: \0;
          padded: \000000;
          interpolated: #{\0};
          tab: \9;
          newline: \a;
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        a {
          null: \0 ;
          padded: \0 ;
          interpolated: \0 ;
          tab: \9 ;
          newline: \a ;
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });
});
