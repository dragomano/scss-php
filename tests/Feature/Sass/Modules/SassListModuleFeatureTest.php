<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\ArrayLogger;

describe('Sass List Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('list.append()', function () {
        it('appends with comma separator', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-append { value: list.append(a b, c, $separator: comma); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-append {
              value: a, b, c;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('appends nested list as bracketed element', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-append { value: list.append(10px 20px, 30px 40px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-append {
              value: 10px 20px (30px 40px);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.index()', function () {
        it('returns 3 for last element in three-element list', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-index { value: list.index(a b c, c); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-index {
              value: 3;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.is-bracketed()', function () {
        it('returns true for bracketed list literal', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-bracketed { value: list.is-bracketed([a, b]); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-bracketed {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.join()', function () {
        it('joins two bracketed lists with slash separator', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-join { value: list.join([1, 2], [3, 4], $separator: slash, $bracketed: true); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-join {
              value: [1 / 2 / 3 / 4];
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.length()', function () {
        it('returns 3 for space-separated list', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-length { value: list.length(a b c); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-length {
              value: 3;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns 2 for map with 2 entries', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-length { value: list.length((width: 10px, height: 20px)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-length {
              value: 2;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.nth()', function () {
        it('returns element at given index', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-nth { value: list.nth(a b c, 2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-nth {
              value: b;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.separator()', function () {
        it('returns comma after append() with comma separator', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-separator { value: list.separator(list.append(a b, c, $separator: comma)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-separator {
              value: comma;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.set-nth()', function () {
        it('replaces element at given index', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-set-nth { value: list.set-nth(a b c, 2, x); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-set-nth {
              value: a x c;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('list.slash()', function () {
        it('creates slash-separated list from two values', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-slash { value: list.slash(10px, 12px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-slash {
              value: 10px / 12px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('logs spaced slash output for multi-element list', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            @debug list.slash(1px, 50px, 100px);
            SCSS;

            $this->compiler->compileString($scss);

            expect($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> 1px / 50px / 100px');
        });
    });

    describe('list.zip()', function () {
        it('zips three parallel lists into comma-separated pairs', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            .list-zip { value: list.zip(10px 12px, solid dashed, red blue); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .list-zip {
              value: 10px solid red, 12px dashed blue;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('global list functions', function () {
        describe('append()', function () {
            it('appends nested list as space-separated element', function () {
                $scss = <<<'SCSS'
                .list-global-append { value: append(10px 20px, 30px 40px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-append {
                  value: 10px 20px (30px 40px);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('index()', function () {
            it('returns 1-based index of element', function () {
                $scss = <<<'SCSS'
                .list-global-index { value: index(1px solid red, 1px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-index {
                  value: 1;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('is-bracketed()', function () {
            it('returns false for space-separated list', function () {
                $scss = <<<'SCSS'
                .list-global-is-bracketed { value: is-bracketed(1px 2px 3px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-is-bracketed {
                  value: false;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('returns true for bracketed list', function () {
                $scss = <<<'SCSS'
                .list-global-is-bracketed { value: is-bracketed([1px, 2px, 3px]); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-is-bracketed {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('join()', function () {
            it('joins two space-separated lists', function () {
                $scss = <<<'SCSS'
                .list-global-join { value: join(10px 20px, 30px 40px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-join {
                  value: 10px 20px 30px 40px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('length()', function () {
            it('returns 2 for map with 2 entries', function () {
                $scss = <<<'SCSS'
                .list-global-length { value: length((width: 10px, height: 20px)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-length {
                  value: 2;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('nth()', function () {
            it('returns element at given index', function () {
                $scss = <<<'SCSS'
                .list-global-nth { value: nth(a b c, 2); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-nth {
                  value: b;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('list-separator()', function () {
            it('returns comma for comma-separated list', function () {
                $scss = <<<'SCSS'
                .list-global-separator { value: list-separator((1px, 2px, 3px)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-separator {
                  value: comma;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('set-nth()', function () {
            it('replaces element at given index in comma list', function () {
                $scss = <<<'SCSS'
                .list-global-set-nth { value: set-nth((Helvetica, Arial, sans-serif), 3, Roboto); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-set-nth {
                  value: Helvetica, Arial, Roboto;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('list-slash()', function () {
            it('creates slash-separated list', function () {
                $scss = <<<'SCSS'
                .list-global-slash { value: list-slash(10px, 12px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-slash {
                  value: 10px / 12px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('zip()', function () {
            it('zips parallel lists of unequal length', function () {
                $scss = <<<'SCSS'
                .list-global-zip { value: zip(10px 50px 100px, short mid); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .list-global-zip {
                  value: 10px short, 50px mid;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });
    });

    it('logs deprecations', function () {
        $scss = <<<'SCSS'
        @debug append(10px 20px, 30px 40px);
        @debug index(1px solid red, 1px);
        @debug is-bracketed(1px 2px 3px);
        @debug join(10px 20px, 30px 40px);
        @debug length((width: 10px, height: 20px));
        @debug nth([line1, line2, line3], -1);
        @debug list-separator((1px, 2px, 3px));
        @debug set-nth((Helvetica, Arial, sans-serif), 3, Roboto);
        @debug zip(10px 50px 100px, short mid);
        SCSS;

        $this->compiler->compileString($scss);

        expect($this->logger->records)->toHaveCount(18)
            ->and($this->logger->records[0]['message'])->toContain('append() is deprecated. Suggestion: list.append(10px 20px, 30px 40px)')
            ->and($this->logger->records[1]['message'])->toContain('10px 20px (30px 40px)')
            ->and($this->logger->records[2]['message'])->toContain('index() is deprecated. Suggestion: list.index(1px solid red, 1px)')
            ->and($this->logger->records[3]['message'])->toContain('1')
            ->and($this->logger->records[4]['message'])->toContain('is-bracketed() is deprecated. Suggestion: list.is-bracketed(1px 2px 3px)')
            ->and($this->logger->records[5]['message'])->toContain('false')
            ->and($this->logger->records[6]['message'])->toContain('join() is deprecated. Suggestion: list.join(10px 20px, 30px 40px)')
            ->and($this->logger->records[7]['message'])->toContain('10px 20px 30px 40px')
            ->and($this->logger->records[8]['message'])->toContain('length() is deprecated. Suggestion: list.length((width: 10px, height: 20px))')
            ->and($this->logger->records[9]['message'])->toContain('2')
            ->and($this->logger->records[10]['message'])->toContain('nth() is deprecated. Suggestion: list.nth([line1, line2, line3], -1)')
            ->and($this->logger->records[11]['message'])->toContain('line3')
            ->and($this->logger->records[12]['message'])->toContain('list-separator() is deprecated. Suggestion: list.separator((1px, 2px, 3px))')
            ->and($this->logger->records[13]['message'])->toContain('comma')
            ->and($this->logger->records[14]['message'])->toContain('set-nth() is deprecated. Suggestion: list.set-nth((Helvetica, Arial, sans-serif), 3, Roboto)')
            ->and($this->logger->records[15]['message'])->toContain('Helvetica, Arial, Roboto')
            ->and($this->logger->records[16]['message'])->toContain('zip() is deprecated. Suggestion: list.zip(10px 50px 100px, short mid)')
            ->and($this->logger->records[17]['message'])->toContain('10px short, 50px mid');
    });
});
