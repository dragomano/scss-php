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

        it('collapses four identical box shorthand components', function () {
            expect($this->formatter->format('.a{margin:8px 8px 8px 8px}'))->toBe('.a{margin:8px}');
        });

        it('collapses mirrored box shorthand components', function () {
            expect($this->formatter->format('.a{margin:10px 20px 10px 20px}'))->toBe('.a{margin:10px 20px}');
        });

        it('drops left box shorthand component when right matches it', function () {
            expect($this->formatter->format('.a{margin:1px 2px 3px 2px}'))->toBe('.a{margin:1px 2px 3px}');
        });

        it('collapses three box shorthand components when bottom matches top', function () {
            expect($this->formatter->format('.a{margin:3px 6px 3px}'))->toBe('.a{margin:3px 6px}');
        });

        it('collapses three identical box shorthand components', function () {
            expect($this->formatter->format('.a{padding:5px 5px 5px}'))->toBe('.a{padding:5px}');
        });

        it('collapses two equal box shorthand components', function () {
            expect($this->formatter->format('.a{margin:4px 4px}'))->toBe('.a{margin:4px}');
        });

        it('keeps box shorthand components that differ', function () {
            expect($this->formatter->format('.a{margin:1px 2px 3px 4px}'))->toBe('.a{margin:1px 2px 3px 4px}');
        });

        it('collapses equal two-sided logical property values', function () {
            expect($this->formatter->format('.a{margin-block:1px 1px}'))->toBe('.a{margin-block:1px}');
        });

        it('keeps differing two-sided logical property values', function () {
            expect($this->formatter->format('.a{margin-inline:1px 2px}'))->toBe('.a{margin-inline:1px 2px}');
        });

        it('collapses identical calc() components as whole units', function () {
            $css = '.a{margin:calc(1px + 2px) 5px calc(1px + 2px) 5px}';

            expect($this->formatter->format($css))->toBe('.a{margin:calc(1px + 2px) 5px}');
        });

        it('collapses identical quoted string components', function () {
            $css = '.a{margin:"a b" "a b"}';

            expect($this->formatter->format($css))->toBe('.a{margin:"a b"}');
        });

        it('collapses declarations that follow other declarations in one block', function () {
            $css = '.a{margin:4px 4px;padding:8px 8px;color:red}';

            expect($this->formatter->format($css))->toBe('.a{margin:4px;padding:8px;color:red}');
        });

        it('collapses box shorthand in the last declaration without trailing semicolon', function () {
            expect($this->formatter->format('{margin:4px 4px}'))->toBe('{margin:4px}');
        });

        it('preserves important flag when collapsing', function () {
            expect($this->formatter->format('.a{margin:4px 4px!important}'))->toBe('.a{margin:4px!important}');
        });

        it('matches box shorthand properties case-insensitively', function () {
            expect($this->formatter->format('.a{MARGIN:4PX 4PX}'))->toBe('.a{MARGIN:4PX}');
        });

        it('leaves custom properties untouched', function () {
            expect($this->formatter->format('.a{--margin:4px 4px}'))->toBe('.a{--margin:4px 4px}');
        });

        it('leaves properties outside the box shorthand list untouched', function () {
            $css = '.a{background:red red;grid-area:1 1 1 1}';

            expect($this->formatter->format($css))->toBe('.a{background:red red;grid-area:1 1 1 1}');
        });

        it('does not collapse slashed border-radius syntax', function () {
            expect($this->formatter->format('.a{border-radius:10px 20px/5px 20px}'))->toBe('.a{border-radius:10px 20px/5px 20px}');
        });

        it('ignores declaration-like text inside quoted strings', function () {
            $css = '.a{content:"margin:4px 4px";margin:4px 4px}';

            expect($this->formatter->format($css))->toBe('.a{content:"margin:4px 4px";margin:4px}');
        });

        it('collapses box shorthand inside nested at-rule blocks', function () {
            $css = '@media screen{.a{margin:4px 4px}}';

            expect($this->formatter->format($css))->toBe('@media screen{.a{margin:4px}}');
        });

        describe('stripZeroUnits', function () {
            it('strips px unit from zero values', function () {
                expect($this->formatter->format('.a{width:0px}'))->toBe('.a{width:0}');
            });

            it('strips em unit from zero values', function () {
                expect($this->formatter->format('.a{margin:0em}'))->toBe('.a{margin:0}');
            });

            it('strips rem unit from zero values', function () {
                expect($this->formatter->format('.a{font-size:0rem}'))->toBe('.a{font-size:0}');
            });

            it('strips multiple zero units in one declaration', function () {
                expect($this->formatter->format('.a{margin:0px 0em 0rem}'))->toBe('.a{margin:0 0 0}');
            });

            it('does not strip unit from non-zero values', function () {
                expect($this->formatter->format('.a{width:10px}'))->toBe('.a{width:10px}');
            });

            it('does not strip percent unit from zero values', function () {
                expect($this->formatter->format('.a{width:0%}'))->toBe('.a{width:0%}');
            });

            it('does not strip units inside quoted strings', function () {
                expect($this->formatter->format('.a{content:"0px"}'))->toBe('.a{content:"0px"}');
            });

            it('strips zero units in calc expressions', function () {
                expect($this->formatter->format('.a{width:calc(100% - 0px)}'))->toBe('.a{width:calc(100% - 0)}');
            });

            it('strips zero units from compound values', function () {
                expect($this->formatter->format('.a{border:0px solid red}'))->toBe('.a{border:0 solid red}');
            });
        });
    });
});
