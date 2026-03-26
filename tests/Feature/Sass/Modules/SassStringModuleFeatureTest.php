<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Tests\ArrayLogger;

describe('Sass String Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('string.index()', function () {
        it('returns 1-based position of substring', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-index { value: string.index(hello, ll); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-index {
              value: 3;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('string.insert()', function () {
        it('inserts string at given position', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-insert { value: string.insert(abcd, X, 3); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-insert {
              value: abXcd;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('preserves quotes when inserting into quoted string', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-insert-quoted { value: string.insert("test", "22", 2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-insert-quoted {
              value: "t22est";
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws for index 0', function () {
            $scss = <<<'SCSS'
            @use "sass:string";

            .test {
              result: string.insert("world", "hello ", 0);
            }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(BuiltinArgumentException::class, 'string.insert() index must not be 0.');
        });
    });

    describe('string.length()', function () {
        it('returns character count', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-length { value: string.length(hello); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-length {
              value: 5;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('string.quote()', function () {
        it('wraps unquoted string in double quotes', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-quote { value: string.quote(hello); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-quote {
              value: "hello";
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('uses single quotes when string contains double quotes', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-quote-inner-double { value: string.quote('mixed"quote'); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-quote-inner-double {
              value: 'mixed"quote';
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('string.slice()', function () {
        it('extracts substring by start and end index', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-slice { value: string.slice(abcdef, 2, 4); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-slice {
              value: bcd;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('extracts from end using negative index', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-slice-negative { value: string.slice("Roboto Mono", -4); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-slice-negative {
              value: "Mono";
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('keeps trailing space for negative end index', function () {
            $scss = <<<'SCSS'
            @use "sass:string";

            .test {
              result: string.slice("hello world", 1, -6);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              result: "hello ";
            }
            CSS;

            expect($this->compiler->compileString($scss))->toEqualCss($expected);
        });
    });

    describe('string.split()', function () {
        it('splits by delimiter into bracketed list', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-split { value: string.split(a-b-c, "-"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-split {
              value: [a, b, c];
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('splits string into characters when separator is empty', function () {
            $scss = <<<'SCSS'
            @use "sass:string";

            .test {
              result: string.split("abc", "");
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              result: ["a", "b", "c"];
            }
            CSS;

            expect($this->compiler->compileString($scss))->toEqualCss($expected);
        });

        it('truncates result list to given limit', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-split-limit { value: string.split("Segoe UI Emoji", " ", $limit: 1); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-split-limit {
              value: ["Segoe", "UI Emoji"];
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('string.to-lower-case()', function () {
        it('converts to lowercase', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-lower { value: string.to-lower-case(AB); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-lower {
              value: ab;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('preserves quotes for quoted input', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-lower-quoted { value: string.to-lower-case("HELLO"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-lower-quoted {
              value: "hello";
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('string.to-upper-case()', function () {
        it('converts to uppercase', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-upper { value: string.to-upper-case(ab); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-upper {
              value: AB;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('preserves quotes for quoted input', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-upper-quoted { value: string.to-upper-case("hello"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-upper-quoted {
              value: "HELLO";
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('string.unique-id()', function () {
        it('returns unique identifier string', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-unique-id { value: string.unique-id(); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-unique-id {
              value: u1;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('string.unquote()', function () {
        it('removes quotes from quoted string', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-unquote { value: string.unquote("hello"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-unquote {
              value: hello;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('keeps empty declaration value for empty string', function () {
            $scss = <<<'SCSS'
            @use "sass:string";

            .test {
              content: string.unquote("");
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              content: ;
            }
            CSS;

            expect($this->compiler->compileString($scss))->toEqualCss($expected);
        });

        it('unquotes string with backslash escapes', function () {
            $scss = <<<'SCSS'
            @use "sass:string";
            .string-unquote-escapes {
              line: string.unquote("line1\\nline2");
              path: string.unquote("path\\to\\file");
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .string-unquote-escapes {
              line: line1\nline2;
              path: path\to\file;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('global string functions', function () {
        describe('quote()', function () {
            it('wraps unquoted string in double quotes', function () {
                $scss = <<<'SCSS'
                .string-global-quote { value: quote(hello); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-quote {
                  value: "hello";
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('str-index()', function () {
            it('returns 1-based position of substring', function () {
                $scss = <<<'SCSS'
                .string-global-index { value: str-index(hello, ll); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-index {
                  value: 3;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('str-insert()', function () {
            it('inserts string at given position', function () {
                $scss = <<<'SCSS'
                .string-global-insert { value: str-insert(abcd, X, 3); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-insert {
                  value: abXcd;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('str-length()', function () {
            it('returns character count', function () {
                $scss = <<<'SCSS'
                .string-global-length { value: str-length(hello); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-length {
                  value: 5;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('str-slice()', function () {
            it('extracts substring by start and end index', function () {
                $scss = <<<'SCSS'
                .string-global-slice { value: str-slice(abcdef, 2, 4); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-slice {
                  value: bcd;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('to-lower-case()', function () {
            it('preserves quotes for quoted input', function () {
                $scss = <<<'SCSS'
                .string-global-lower {
                  value: to-lower-case("AB");
                }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-lower {
                  value: "ab";
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('to-upper-case()', function () {
            it('preserves quotes for quoted input', function () {
                $scss = <<<'SCSS'
                .string-global-upper {
                  value: to-upper-case("ab");
                }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-upper {
                  value: "AB";
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('unique-id()', function () {
            it('returns unique identifier string', function () {
                $scss = <<<'SCSS'
                .string-global-unique-id { value: unique-id(); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-unique-id {
                  value: u1;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('unquote()', function () {
            it('removes quotes from quoted string', function () {
                $scss = <<<'SCSS'
                .string-global-unquote { value: unquote("hello"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .string-global-unquote {
                  value: hello;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('keeps empty declaration value for empty string', function () {
                $scss = <<<'SCSS'
                .test {
                  content: unquote("");
                }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .test {
                  content: ;
                }
                CSS;

                expect($this->compiler->compileString($scss))->toEqualCss($expected);
            });
        });
    });

    it('logs deprecations', function () {
        $scss = <<<'SCSS'
        @debug str-index("Helvetica Neue", "Neue");
        @debug str-insert("Roboto Bold", " Mono", 7);
        @debug str-length("Helvetica Neue");
        @debug quote(Helvetica);
        @debug str-slice("Helvetica Neue", 1, 10);
        @debug to-lower-case("AB");
        @debug to-upper-case("ab");
        @debug unique-id();
        @debug unquote("Helvetica");
        SCSS;

        $this->compiler->compileString($scss);

        expect($this->logger->records)->toHaveCount(18)
            ->and($this->logger->records[0]['message'])->toContain('str-index() is deprecated. Suggestion: string.index("Helvetica Neue", "Neue")')
            ->and($this->logger->records[1]['message'])->toContain('11')
            ->and($this->logger->records[2]['message'])->toContain('str-insert() is deprecated. Suggestion: string.insert("Roboto Bold", " Mono", 7)')
            ->and($this->logger->records[3]['message'])->toContain('Roboto Mono Bold')
            ->and($this->logger->records[4]['message'])->toContain('str-length() is deprecated. Suggestion: string.length("Helvetica Neue")')
            ->and($this->logger->records[5]['message'])->toContain('14')
            ->and($this->logger->records[6]['message'])->toContain('quote() is deprecated. Suggestion: string.quote(Helvetica)')
            ->and($this->logger->records[7]['message'])->toContain('Helvetica')
            ->and($this->logger->records[8]['message'])->toContain('str-slice() is deprecated. Suggestion: string.slice("Helvetica Neue", 1, 10)')
            ->and($this->logger->records[9]['message'])->toContain('Helvetica ')
            ->and($this->logger->records[10]['message'])->toContain('to-lower-case() is deprecated. Suggestion: string.to-lower-case("AB")')
            ->and($this->logger->records[11]['message'])->toContain('ab')
            ->and($this->logger->records[12]['message'])->toContain('to-upper-case() is deprecated. Suggestion: string.to-upper-case("ab")')
            ->and($this->logger->records[13]['message'])->toContain('AB')
            ->and($this->logger->records[14]['message'])->toContain('unique-id() is deprecated. Suggestion: string.unique-id()')
            ->and($this->logger->records[15]['message'])->toContain('u1')
            ->and($this->logger->records[16]['message'])->toContain('unquote() is deprecated. Suggestion: string.unquote("Helvetica")')
            ->and($this->logger->records[17]['message'])->toContain('Helvetica');
    });
});
