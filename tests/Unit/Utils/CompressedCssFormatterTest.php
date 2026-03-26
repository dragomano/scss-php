<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\CompressedCssFormatter;

describe('CompressedCssFormatter', function () {
    beforeEach(function () {
        $this->formatter = new CompressedCssFormatter();
    });

    describe('format()', function () {
        it('strips whitespace between declarations', function () {
            $css = ".a {\n  color: red;\n  margin: 0;\n}";

            expect($this->formatter->format($css))->toBe('.a{color:red;margin:0}');
        });

        it('removes trailing semicolon before closing brace', function () {
            expect($this->formatter->format('.a { color: red; }'))->toBe('.a{color:red}');
        });

        it('removes regular comments', function () {
            expect($this->formatter->format('.a { /* comment */ color: red; }'))->toBe('.a{color:red}');
        });

        it('preserves bang-important comments', function () {
            $css = '/*! license */ .a { color: red; }';

            expect($this->formatter->format($css))->toBe('/*! license */.a{color:red}');
        });

        it('preserves sourceMappingURL comments', function () {
            $css = '.a{color:red}/* # sourceMappingURL=out.css.map */';

            expect($this->formatter->format($css))->toBe('.a{color:red}/* # sourceMappingURL=out.css.map */');
        });

        it('shortens 6-digit hex colors to 3-digit when possible', function () {
            expect($this->formatter->format('.a{color:#ffffff}'))->toBe('.a{color:#fff}');
        });

        it('leaves 6-digit hex colors that cannot be shortened', function () {
            expect($this->formatter->format('.a{color:#ff0001}'))->toBe('.a{color:#ff0001}');
        });

        it('does not shorten hex colors inside quoted strings', function () {
            expect($this->formatter->format('.a{content:"#ffffff"}'))->toBe('.a{content:"#ffffff"}');
        });

        it('replaces hue-rotate(0deg) with hue-rotate(0)', function () {
            expect($this->formatter->format('.a{filter:hue-rotate(0deg)}'))->toBe('.a{filter:hue-rotate(0)}');
        });

        it('trims surrounding whitespace from result', function () {
            expect($this->formatter->format('  .a{color:red}  '))->toBe('.a{color:red}');
        });

        it('keeps spaces inside calc() expressions', function () {
            $css = '.a { width: calc(100% - 20px); }';

            expect($this->formatter->format($css))->toBe('.a{width:calc(100% - 20px)}');
        });

        it('preserves quoted string content as-is', function () {
            $css = '.a { content: "hello world"; }';

            expect($this->formatter->format($css))->toBe('.a{content:"hello world"}');
        });

        it('keeps spaces between selector and block', function () {
            expect($this->formatter->format('.a .b { color: red; }'))->toBe('.a .b{color:red}');
        });
    });
});
