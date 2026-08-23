<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;

describe('Escaped hash protection', function () {
    beforeEach(function () {
        $this->compiler = new Compiler();
    });

    it('keeps \\#-escaped hashes literal inside quoted declaration values', function () {
        $css = $this->compiler->compileString('.a { p1: "[\#{literal}]"; }');

        $expected = /** @lang text */ <<<'CSS'
        .a {
          p1: "[#{literal}]";
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('keeps \\#-escaped hashes literal in single-quoted values', function () {
        $css = $this->compiler->compileString(".a { p2: '\\#{literal}'; }");

        $expected = /** @lang text */ <<<'CSS'
        .a {
          p2: "#{literal}";
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('protects escaped hashes resolved through interpolation regions', function () {
        $css = $this->compiler->compileString('.a { q1: #{\'[\#{literal}]\'}; }');

        $expected = /** @lang text */ <<<'CSS'
        .a {
          q1: [#{literal}];
        }
        CSS;

        expect($css)->toEqualCss($expected);
    });

    it('does not emit a charset rule for output that is ascii after restoration', function () {
        $css = $this->compiler->compileString('.a { c: "\#{x}"; }');

        expect($css)->toContain('"#{x}"');
        expect($css)->not->toContain('@charset');
    });
});
