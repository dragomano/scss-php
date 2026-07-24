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

        it('keeps trailing content when a regular comment is not closed', function () {
            expect($this->formatter->format('.a{/* unclosed'))->toBe('.a{/* unclosed');
        });

        it('shortens 6-digit hex colors to 3-digit when possible', function () {
            expect($this->formatter->format('.a{color:#ffffff}'))->toBe('.a{color:#fff}');
        });

        it('shortens 8-digit hex colors to 4-digit when possible', function () {
            expect($this->formatter->format('.a{color:#ffffffff}'))->toBe('.a{color:#ffff}');
        });

        it('leaves 6-digit hex colors that cannot be shortened', function () {
            expect($this->formatter->format('.a{color:#ff0001}'))->toBe('.a{color:#ff0001}');
        });

        it('does not shorten hex colors inside quoted strings', function () {
            expect($this->formatter->format('.a{content:"#ffffff"}'))->toBe('.a{content:"#ffffff"}');
        });

        it('does not shorten hash fragments when more hex digits follow a color-length prefix', function () {
            $css = '.a{filter:url(#fffffffff)}';

            expect($this->formatter->format($css))->toBe('.a{filter:url(#fffffffff)}');
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

        it('keeps escaped quotes inside strings', function () {
            $css = '.a { content: "a\"b"; color: red; }';

            expect($this->formatter->format($css))->toBe('.a{content:"a\"b";color:red}');
        });

        it('keeps spaces between selector and block', function () {
            expect($this->formatter->format('.a .b { color: red; }'))->toBe('.a .b{color:red}');
        });

        it('skips space before preserved comments that follow a value', function () {
            $css = '.a{width:calc(1px /*!keep*/ / 2px)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px/*!keep*//2px)}');
        });

        it('keeps a terminal semicolon when no non-space character follows it', function () {
            expect($this->formatter->format('@charset "UTF-8";'))->toBe('@charset "UTF-8";');
        });

        it('removes trailing semicolon before closing brace even with extra whitespace', function () {
            expect($this->formatter->format('.a{color:red; }'))->toBe('.a{color:red}');
        });

        it('skips space after closing paren followed by identifier in non-calc context', function () {
            $css = '.a{width:calc(100% - 20px)em}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(100% - 20px)em}');
        });

        it('skips space before multiplication or division operator inside calc', function () {
            $css = '.a{width:calc(1px *2px)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px*2px)}');
        });

        it('does not skip space for non-calc context multiplication', function () {
            $css = '.a{content:"hello" * 2}';

            expect($this->formatter->format($css))->toBe('.a{content:"hello" * 2}');
        });

        it('treats digits as math operand start in calc expressions', function () {
            $css = '.a{width:calc(1px/ 2)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px/2)}');
        });

        it('treats letters as math operand start in calc expressions', function () {
            $css = '.a{width:calc(1px * a)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px*a)}');
        });

        it('treats percent as math operand start in calc expressions', function () {
            $css = '.a{width:calc(100% / 2)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(100%/2)}');
        });

        it('treats dollar sign as math operand start in calc expressions', function () {
            $css = '.a{width:calc(1px * $var)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px*$var)}');
        });

        it('treats minus as math operand start in calc expressions', function () {
            $css = '.a{width:calc(1px * -1)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px*-1)}');
        });

        it('treats hash as math operand start in calc expressions', function () {
            $css = '.a{width:calc(1px * #foo)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px*#foo)}');
        });

        it('treats dot as math operand start in calc expressions', function () {
            $css = '.a{width:calc(1px * .5)}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px*.5)}');
        });

        it('treats open paren as math operand start in calc expressions', function () {
            $css = '.a{width:calc(1px * (1 + 2))}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(1px*(1 + 2))}');
        });

        it('treats uppercase letters as identifier start after closing paren', function () {
            $css = '.a{width:calc(100% - 20px) Em}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(100% - 20px)Em}');
        });

        it('treats underscore as identifier start after closing paren', function () {
            $css = '.a{width:calc(100% - 20px) _var}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(100% - 20px)_var}');
        });

        it('treats hyphen as identifier start after closing paren', function () {
            $css = '.a{width:calc(100% - 20px) -var}';

            expect($this->formatter->format($css))->toBe('.a{width:calc(100% - 20px)-var}');
        });

        it('leaves non-shortable 6-digit hex colors intact', function () {
            expect($this->formatter->format('.a{color:#123456}'))->toBe('.a{color:#123456}');
        });

        it('leaves non-shortable 8-digit hex colors intact', function () {
            expect($this->formatter->format('.a{color:#12345678}'))->toBe('.a{color:#12345678}');
        });

        it('shortens 8-digit hex colors with mixed paired digits', function () {
            expect($this->formatter->format('.a{color:#aabb1122}'))->toBe('.a{color:#ab12}');
        });

        it('does not treat single hash as hex color', function () {
            $css = '.a{content:"#"}';

            expect($this->formatter->format($css))->toBe('.a{content:"#"}');
        });

        it('handles multiple consecutive hex colors', function () {
            $css = '.a{color:#ffffff;background:#000000}';

            expect($this->formatter->format($css))->toBe('.a{color:#fff;background:#000}');
        });
    });
});
