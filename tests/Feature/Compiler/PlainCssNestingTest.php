<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Loader;

function compilePlainCss(string $source, array $files, string $tmpDir): string
{
    foreach ($files as $name => $content) {
        file_put_contents($tmpDir . '/' . $name . '.css', $content);
    }

    return (new Compiler(loader: new Loader([$tmpDir])))->compileString($source);
}

describe('plain CSS nesting', function () {
    beforeEach(function () {
        $this->tmpDir = sys_get_temp_dir() . '/dart-sass-plain-css-nesting-' . uniqid('', true);
        mkdir($this->tmpDir, 0777, true);
    });

    describe('via top-level @use', function () {
        it('preserves adjacent slashes in a prefetched CSS module', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {b: 1///bar;}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              b: 1 / / / bar;
            }
            CSS);
        });

        it('preserves one level of nesting', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {b {c: d}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              b {
                c: d;
              }
            }
            CSS);
        });

        it('preserves two levels of nesting', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {b {c {d: e}}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              b {
                c {
                  d: e;
                }
              }
            }
            CSS);
        });

        it('keeps nested rules with parent selector', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {&.b {c: d}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              &.b {
                c: d;
              }
            }
            CSS);
        });

        it('keeps adjacent nested rules and declarations in order', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => "a {\n  b: c;\n  d {e: f}\n  g: h;\n}"], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              b: c;
              d {
                e: f;
              }
              g: h;
            }
            CSS);
        });

        it('keeps nested rules with combinators', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {+ b {c: d}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              + b {
                c: d;
              }
            }
            CSS);
        });

        it('keeps nested complex selectors', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a, b {c, d {e: f}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a, b {
              c, d {
                e: f;
              }
            }
            CSS);
        });

        it('bubbles nested media to the top', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {@media b {c: d}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            @media b {
              a {
                c: d;
              }
            }
            CSS);
        });

        it('keeps media nested inside nested rules', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a { b {@media c {d: e}}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              b {
                @media c {
                  d: e;
                }
              }
            }
            CSS);
        });

        it('bubbles nested media with nested rules', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => "a {\n  @media b {\n    c {\n      @media (d) {\n        e: f;\n      }\n    }\n  }\n}"], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            @media b {
              a {
                c {
                  @media (d) {
                    e: f;
                  }
                }
              }
            }
            CSS);
        });

        it('bubbles nested supports to the top', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {@supports (b: c) {d: e}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            @supports (b: c) {
              a {
                d: e;
              }
            }
            CSS);
        });

        it('bubbles unknown at-rules to the top', function () {
            $css = compilePlainCss('@use "plain";', ['plain' => 'a {@b {c: d}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            @b {
              a {
                c: d;
              }
            }
            CSS);
        });
    });

    describe('via nested @import', function () {
        it('qualifies one level with the parent selector', function () {
            $css = compilePlainCss('a {@import "plain"}', ['plain' => 'b {c: d}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a b {
              c: d;
            }
            CSS);
        });

        it('qualifies two levels with the parent selector', function () {
            $css = compilePlainCss('a {@import "plain"}', ['plain' => 'b {c {d: e}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a b {
              c {
                d: e;
              }
            }
            CSS);
        });

        it('wraps top-level parent selector rules', function () {
            $css = compilePlainCss('a {@import "plain"}', ['plain' => '& {b {c: d}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              & {
                b {
                  c: d;
                }
              }
            }
            CSS);
        });
    });

    describe('via nested meta.load-css', function () {
        it('qualifies one level with the parent selector', function () {
            $css = compilePlainCss('@use "sass:meta"; a {@include meta.load-css("plain")}', ['plain' => 'b {c: d}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a b {
              c: d;
            }
            CSS);
        });

        it('qualifies two levels with the parent selector', function () {
            $css = compilePlainCss('@use "sass:meta"; a {@include meta.load-css("plain")}', ['plain' => 'b {c {d: e}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a b {
              c {
                d: e;
              }
            }
            CSS);
        });

        it('wraps top-level parent selector rules', function () {
            $css = compilePlainCss('@use "sass:meta"; a {@include meta.load-css("plain")}', ['plain' => '& {b {c: d}}'], $this->tmpDir);

            expect($css)->toEqualCss(/** @lang text */ <<<'CSS'
            a {
              & {
                b {
                  c: d;
                }
              }
            }
            CSS);
        });
    });
});
