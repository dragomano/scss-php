<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Unicode boundary serialization', function () {
    it('escapes the maximum valid code point without adding a charset', function () {
        $source = <<<'SCSS'
        @use "sass:string";
        $value: "\10FFFF";
        a {
          quoted: $value;
          single-quoted: '\10FFFF';
          unquoted: string.unquote($value);
          interpolated: #{$value};
          hex-after: "\10FFFFa";
          space-after: "\10FFFF  q";
          plain-after: "\10FFFFq";
          length: string.length($value);
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        a {
          quoted: "\10ffff";
          single-quoted: "\10ffff";
          unquoted: \10ffff;
          interpolated: \10ffff;
          hex-after: "\10ffff a";
          space-after: "\10ffff  q";
          plain-after: "\10ffffq";
          length: 1;
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });

    it('preserves neighboring valid code points and replaces out-of-range escapes', function () {
        $source = <<<'SCSS'
        a {
          bmp-noncharacter: "\FFFF";
          below-supplementary-private: "\EFFFF";
          first-supplementary-private: "\F0000";
          last-plane-f-private: "\FFFFD";
          plane-f-penultimate: "\FFFFE";
          plane-f-last: "\FFFFF";
          first-final-plane-private: "\100000";
          last-private: "\10FFFD";
          penultimate: "\10FFFE";
          maximum: "\10FFFF";
          invalid: "\110000";
          emoji: "\1F600";
        }
        SCSS;

        $expected = /** @lang text */ <<<'CSS'
        @charset "UTF-8";
        a {
          bmp-noncharacter: "￿";
          below-supplementary-private: "󯿿";
          first-supplementary-private: "\f0000";
          last-plane-f-private: "\ffffd";
          plane-f-penultimate: "\ffffe";
          plane-f-last: "\fffff";
          first-final-plane-private: "\100000";
          last-private: "\10fffd";
          penultimate: "\10fffe";
          maximum: "\10ffff";
          invalid: "�";
          emoji: "😀";
        }
        CSS;

        expect((new Compiler())->compileString($source))->toEqualCss($expected);
    });
});
