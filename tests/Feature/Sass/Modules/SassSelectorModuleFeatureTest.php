<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\SassErrorException;
use Tests\ArrayLogger;

describe('Sass Selector Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('selector.append()', function () {
        it('appends suffix to selector', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-append { value: selector.append(".button", ".primary"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-append {
              value: .button.primary;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('appends multiple comma-separated suffixes to selector', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-append-list { value: selector.append(".accordion", "__copy, __image"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-append-list {
              value: .accordion__copy, .accordion__image;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('selector.extend()', function () {
        it('extends selector with replacement', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-extend { value: selector.extend(".alert", ".alert", ".message"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-extend {
              value: .alert, .message;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('extends with type and class selector', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-extend-order { value: selector.extend("a.disabled", "a", ".link"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-extend-order {
              value: a.disabled, .link.disabled;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('ignores empty selector parts caused by trailing commas', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-extend-empty-parts { value: selector.extend(".alert, ", ".alert, ", ".message"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-extend-empty-parts {
              value: .alert, .message;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws for complex selector target', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .x { value: selector.extend(".a", ".a .b", ".c"); }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(SassErrorException::class, 'Complex selectors may not be extended');
        });

        it('throws for compound selector target', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .x { value: selector.extend(".a", ".a.b", ".c"); }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(SassErrorException::class, 'Compound selectors may not be extended');
        });
    });

    describe('selector.is-superselector()', function () {
        it('returns true when first is superset of second', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-super { value: selector.is-superselector(".a", ".a.b"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-super {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('selector.nest()', function () {
        it('nests child under parent selector', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-nest { value: selector.nest(".a", "&:hover"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-nest {
              value: .a:hover;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('nests under multiple parent selectors', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-nest-list { value: selector.nest(".alert, .warning", "p"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-nest-list {
              value: .alert p, .warning p;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('selector.parse()', function () {
        it('parses selector string to structured form', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-parse { value: selector.parse(".a > .b"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-parse {
              value: .a > .b;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('selector.replace()', function () {
        it('replaces selector target with substitute', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-replace { value: selector.replace(".alert", ".alert", ".message"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-replace {
              value: .message;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('replaces with type and class selector', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-replace-order { value: selector.replace("a.disabled", "a", ".link"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-replace-order {
              value: .link.disabled;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('selector.simple-selectors()', function () {
        it('splits compound selector into simple parts', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-simple { value: selector.simple-selectors(".a.b"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-simple {
              value: .a, .b;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('selector.unify()', function () {
        it('merges two simple selectors', function () {
            $scss = <<<'SCSS'
            @use "sass:selector";
            .selector-unify { value: selector.unify(".a", ".b"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .selector-unify {
              value: .a.b;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('global selector functions', function () {
        describe('selector-append()', function () {
            it('appends suffix to selector', function () {
                $scss = <<<'SCSS'
                .selector-global-append { value: selector-append(".button", ".primary"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-append {
                  value: .button.primary;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('selector-extend()', function () {
            it('extends selector with replacement', function () {
                $scss = <<<'SCSS'
                .selector-global-extend { value: selector-extend(".alert", ".alert", ".message"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-extend {
                  value: .alert, .message;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('is-superselector()', function () {
            it('returns true when first is superset of second', function () {
                $scss = <<<'SCSS'
                .selector-global-super { value: is-superselector(".a", ".a.b"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-super {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('selector-nest()', function () {
            it('nests child under parent selector', function () {
                $scss = <<<'SCSS'
                .selector-global-nest { value: selector-nest(".a", "&:hover"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-nest {
                  value: .a:hover;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('selector-parse()', function () {
            it('parses selector string to structured form', function () {
                $scss = <<<'SCSS'
                .selector-global-parse { value: selector-parse(".a > .b"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-parse {
                  value: .a > .b;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('selector-replace()', function () {
            it('replaces selector target with substitute', function () {
                $scss = <<<'SCSS'
                .selector-global-replace { value: selector-replace(".alert", ".alert", ".message"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-replace {
                  value: .message;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('simple-selectors()', function () {
            it('splits compound selector into simple parts', function () {
                $scss = <<<'SCSS'
                .selector-global-simple { value: simple-selectors(".a.b"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-simple {
                  value: .a, .b;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('selector-unify()', function () {
            it('merges two simple selectors', function () {
                $scss = <<<'SCSS'
                .selector-global-unify { value: selector-unify(".a", ".b"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .selector-global-unify {
                  value: .a.b;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });
    });

    it('logs deprecations', function () {
        $scss = <<<'SCSS'
        @debug selector-append(".accordion", "__copy");
        @debug selector-extend("a.disabled", "h1", "h2");
        @debug is-superselector("a.disabled", "a");
        @debug selector-nest(".alert, .warning", "p");
        @debug selector-parse(".main aside:hover, .sidebar p");
        @debug selector-replace("a.disabled", "h1", "h2");
        @debug simple-selectors("main.blog:after");
        @debug selector-unify("a.disabled", "a.outgoing");
        SCSS;

        $this->compiler->compileString($scss);

        expect($this->logger->records)->toHaveCount(16)
            ->and($this->logger->records[0]['message'])->toContain('selector-append() is deprecated. Suggestion: selector.append(".accordion", "__copy")')
            ->and($this->logger->records[1]['message'])->toContain('.accordion__copy')
            ->and($this->logger->records[2]['message'])->toContain('selector-extend() is deprecated. Suggestion: selector.extend("a.disabled", "h1", "h2")')
            ->and($this->logger->records[3]['message'])->toContain('a.disabled')
            ->and($this->logger->records[4]['message'])->toContain('is-superselector() is deprecated. Suggestion: selector.is-superselector("a.disabled", "a")')
            ->and($this->logger->records[5]['message'])->toContain('false')
            ->and($this->logger->records[6]['message'])->toContain('selector-nest() is deprecated. Suggestion: selector.nest(".alert, .warning", "p")')
            ->and($this->logger->records[7]['message'])->toContain('.alert p, .warning p')
            ->and($this->logger->records[8]['message'])->toContain('selector-parse() is deprecated. Suggestion: selector.parse(".main aside:hover, .sidebar p")')
            ->and($this->logger->records[9]['message'])->toContain('.main aside:hover, .sidebar p')
            ->and($this->logger->records[10]['message'])->toContain('selector-replace() is deprecated. Suggestion: selector.replace("a.disabled", "h1", "h2")')
            ->and($this->logger->records[11]['message'])->toContain('a.disabled')
            ->and($this->logger->records[12]['message'])->toContain('simple-selectors() is deprecated. Suggestion: selector.simple-selectors("main.blog:after")')
            ->and($this->logger->records[13]['message'])->toContain('main, .blog, :after')
            ->and($this->logger->records[14]['message'])->toContain('selector-unify() is deprecated. Suggestion: selector.unify("a.disabled", "a.outgoing")')
            ->and($this->logger->records[15]['message'])->toContain('a.disabled.outgoing');
    });
});
