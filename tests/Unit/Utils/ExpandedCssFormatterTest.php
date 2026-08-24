<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\ExpandedCssFormatter;

describe('ExpandedCssFormatter', function () {
    beforeEach(function () {
        $this->formatter = new ExpandedCssFormatter();
    });

    describe('format()', function () {
        it('adds blank lines between root rules', function () {
            $source = /** @lang text */ <<<'SCSS'
            .first { width: 1px; }
            .second { width: 2px; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .first { width: 1px; }

            .second { width: 2px; }
            CSS;

            expect($this->formatter->format($source))->toBe($expected);
        });

        it('keeps blank lines already present in the input', function () {
            $source = /** @lang text */ <<<'SCSS'
            .a { width: 1px; }

            .b { width: 2px; }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });

        it('flattens nested rules and adds blank lines between root rules', function () {
            $source = /** @lang text */ <<<'SCSS'
            .a {
              .b { width: 1px; }
            }
            .c { width: 2px; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a .b { width: 1px; }

            .c { width: 2px; }
            CSS;

            expect($this->formatter->format($source))->toBe($expected);
        });

        it('does not flatten a nested rule that has sibling declarations', function () {
            $source = /** @lang text */ <<<'SCSS'
            .a {
              color: red;
              .b { width: 1px; }
            }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });

        it('does not flatten a nested at-rule block', function () {
            $source = /** @lang text */ <<<'SCSS'
            .a {
              @media (min-width: 100px) { width: 1px; }
            }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });

        it('does not flatten a nested rule spanning multiple lines', function () {
            $source = /** @lang text */ <<<'SCSS'
            .a {
              .b {
                width: 1px;
              }
            }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });

        it('keeps a single newline between consecutive font-face rules', function () {
            $source = /** @lang text */ <<<'SCSS'
            @font-face { font-family: "A"; }
            @font-face { font-family: "B"; }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });

        it('keeps a single newline between consecutive keyframes rules', function () {
            $source = /** @lang text */ <<<'SCSS'
            @keyframes spin { from { opacity: 0; } to { opacity: 1; } }
            .a { width: 1px; }
            SCSS;

            $result = $this->formatter->format($source);

            expect($result)->toContain("@keyframes spin { from { opacity: 0; } to { opacity: 1; } }\n.a");
        });

        it('keeps a single newline after an inline comment', function () {
            $source = /** @lang text */ <<<'SCSS'
            /* comment */
            .a { width: 1px; }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });

        it('keeps a single newline after an @import statement', function () {
            $source = /** @lang text */ <<<'SCSS'
            @import "reset";
            .a { width: 1px; }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });

        it('adds a blank line between a rule and its pseudo-class variant', function () {
            $source = /** @lang text */ <<<'SCSS'
            .a { width: 1px; }
            .a:hover { width: 2px; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a { width: 1px; }

            .a:hover { width: 2px; }
            CSS;

            expect($this->formatter->format($source))->toBe($expected);
        });

        it('does not treat a pseudo-class variant as such when the selector is a list', function () {
            $source = /** @lang text */ <<<'SCSS'
            .a, .b { width: 1px; }
            .a:hover { width: 2px; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .a, .b { width: 1px; }

            .a:hover { width: 2px; }
            CSS;

            expect($this->formatter->format($source))->toBe($expected);
        });

        it('keeps a single newline between merged media query continuations', function () {
            $source = /** @lang text */ <<<'SCSS'
            @media (min-width: 100px) { .a { width: 1px; } }
            @media (min-width: 100px) and (max-width: 200px) { .a { width: 2px; } }
            SCSS;

            expect($this->formatter->format($source))->toBe($source);
        });
    });
});
