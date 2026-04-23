<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\InvalidSyntaxException;
use Bugo\SCSS\Normalizers\SassNormalizer;

describe('SassNormalizer', function () {
    beforeEach(function () {
        $this->normalizer = new SassNormalizer();
    });

    it('converts variables and simple rules', function () {
        $sass = <<<'SASS'
        $primary-color: #333
        $padding: 10px

        .nav
          color: $primary-color
          padding: $padding
        SASS;

        $expected = <<<'SCSS'
        $primary-color: #333;
        $padding: 10px;

        .nav {
          color: $primary-color;
          padding: $padding;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('converts nested selectors', function () {
        $sass = <<<'SASS'
        .nav
          color: red
          &:hover
            color: blue
        SASS;

        $expected = <<<'SCSS'
        .nav {
          color: red;
          &:hover {
            color: blue;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('converts mixins and includes to valid scss', function () {
        $sass = <<<'SASS'
        =button
          padding: 10px
          color: white

        .button
          +button
        SASS;

        $expected = <<<'SCSS'
        @mixin button {
          padding: 10px;
          color: white;
        }

        .button {
          @include button;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles trailing spaces after commas', function () {
        $sass = 'font-family: Arial,   ';

        $expected = 'font-family: Arial,';

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('converts mixins with parameters', function () {
        $sass = <<<'SASS'
        =button($size, $color)
          padding: $size
          background: $color

        .btn
          +button(10px, blue)
        SASS;

        $expected = <<<'SCSS'
        @mixin button($size, $color) {
          padding: $size;
          background: $color;
        }

        .btn {
          @include button(10px, blue);
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('converts control directives', function () {
        $sass = <<<'SASS'
        $flag: true

        .container
          @if $flag
            color: green
          @else
            color: red
        SASS;

        $expected = <<<'SCSS'
        $flag: true;

        .container {
          @if $flag {
            color: green;
          }
          @else {
            color: red;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('keeps comments and blank lines', function () {
        $sass = <<<'SASS'
        // Comment
        .container
          // inner comment
          color: red

        .footer
          color: blue
        SASS;

        $expected = <<<'SCSS'
        // Comment
        .container {
          // inner comment
          color: red;
        }

        .footer {
          color: blue;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles multiple nesting levels', function () {
        $sass = <<<'SASS'
        .a
          .b
            .c
              color: red
        SASS;

        $expected = <<<'SCSS'
        .a {
          .b {
            .c {
              color: red;
            }
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles @media blocks', function () {
        $sass = <<<'SASS'
        .container
          @media screen
            color: red
        SASS;

        $expected = <<<'SCSS'
        .container {
          @media screen {
            color: red;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles standalone properties', function () {
        $sass = <<<'SASS'
        a
          color: red
          margin: 0
        SASS;

        $expected = <<<'SCSS'
        a {
          color: red;
          margin: 0;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles multiline comments', function () {
        $sass = <<<'SASS'
        /* This is a
           multiline comment */
        .box
          color: red
        SASS;

        $expected = <<<'SCSS'
        /* This is a
           multiline comment */
        .box {
          color: red;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles single line directives', function () {
        $sass = <<<'SASS'
        @import "variables"
        @use "mixins"
        @forward "base"

        .btn
          @extend %button-base
          color: blue
        SASS;

        $expected = <<<'SCSS'
        @import "variables";
        @use "mixins";
        @forward "base";

        .btn {
          @extend %button-base;
          color: blue;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles @function blocks', function () {
        $sass = <<<'SASS'
        @function double($n)
          @return $n * 2

        .box
          width: double(50px)
        SASS;

        $expected = <<<'SCSS'
        @function double($n) {
          @return $n * 2;
        }

        .box {
          width: double(50px);
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles keyframes with percentages', function () {
        $sass = <<<'SASS'
        @keyframes fade
          0%
            opacity: 0
          100%
            opacity: 1
        SASS;

        $expected = <<<'SCSS'
        @keyframes fade {
          0% {
            opacity: 0;
          }
          100% {
            opacity: 1;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles pseudo-classes and pseudo-elements', function () {
        $sass = <<<'SASS'
        a
          color: blue
          &:hover
            color: red
          &::before
            content: "→"
        SASS;

        $expected = <<<'SCSS'
        a {
          color: blue;
          &:hover {
            color: red;
          }
          &::before {
            content: "→";
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles nested properties', function () {
        $sass = <<<'SASS'
        .text
          font:
            size: 14px
            weight: bold
            family: Arial
        SASS;

        $expected = <<<'SCSS'
        .text {
          font: {
            size: 14px;
            weight: bold;
            family: Arial;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles @for loops', function () {
        $sass = <<<'SASS'
        @for $i from 1 through 3
          .col-#{$i}
            width: 100% / $i
        SASS;

        $expected = <<<'SCSS'
        @for $i from 1 through 3 {
          .col-#{$i} {
            width: 100% / $i;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles multiline parenthesized declarations and split @for headers', function () {
        $sass = <<<'SASS'
        .grid
          display: grid
          grid-template: (
            "header" min-content
            "main" 1fr
          )

        @for
          $i from
          1 through 3
            ul:nth-child(3n + #{$i})
              margin-left: $i * 10
        SASS;

        $expected = <<<'SCSS'
        .grid {
          display: grid;
          grid-template: ( "header" min-content "main" 1fr );
        }

        @for $i from 1 through 3 {
            ul:nth-child(3n + #{$i}) {
              margin-left: $i * 10;
            }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('throws when a split directive header is separated by an empty line', function () {
        $sass = <<<'SASS'
        @for

          $i from 1 through 3
            color: red
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(
                InvalidSyntaxException::class,
                "Directive header continuation for '@for' cannot be separated by an empty line after line 1.",
            );
    });

    it('throws when a split directive header is separated by multiple empty lines', function () {
        $sass = <<<'SASS'
        @for


          $i from 1 through 3
            color: red
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(
                InvalidSyntaxException::class,
                "Directive header continuation for '@for' cannot be separated by an empty line after line 1.",
            );
    });

    it('throws when a directive header stays incomplete after an empty line', function () {
        $sass = <<<'SASS'
        @for

        text
          color: red
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(InvalidSyntaxException::class, "Incomplete directive header for '@for' at line 1.");
    });

    it('throws when a parenthesized declaration is interrupted by an empty line', function () {
        $sass = <<<'SASS'
        .grid
          grid-template: (

          color: red
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(InvalidSyntaxException::class, "Expected closing ')' for '(' opened at line 2.");
    });

    it('throws when a balanced parenthesized declaration is interrupted by an empty line', function () {
        $sass = <<<'SASS'
        .grid
          grid-template: (

          )
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(InvalidSyntaxException::class, "Expected closing ')' for '(' opened at line 2.");
    });

    it('throws when a parenthesized declaration dedents before closing', function () {
        $sass = <<<'SASS'
        .grid
          grid-template: (
        other
          color: red
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(InvalidSyntaxException::class, "Expected closing ')' for '(' opened at line 2.");
    });

    it('throws when a balanced parenthesized declaration dedents before closing', function () {
        $sass = <<<'SASS'
        .grid
          grid-template: (
        )
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(InvalidSyntaxException::class, "Expected closing ')' for '(' opened at line 2.");
    });

    it('throws when a same-level parenthesized continuation does not close the declaration', function () {
        $sass = <<<'SASS'
        .grid
          grid-template: (
          "header"
          color: red
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(InvalidSyntaxException::class, "Expected closing ')' for '(' opened at line 2.");
    });

    it('throws when a balanced same-level continuation does not close the parenthesized declaration', function () {
        $sass = <<<'SASS'
        .grid
          grid-template: (
          foo
          )
        SASS;

        expect(fn() => $this->normalizer->normalize($sass))
            ->toThrow(InvalidSyntaxException::class, "Expected closing ')' for '(' opened at line 2.");
    });

    it('handles @each loops', function () {
        $sass = <<<'SASS'
        @each $color in red, green, blue
          .#{$color}
            background: $color
        SASS;

        $expected = <<<'SCSS'
        @each $color in red, green, blue {
          .#{$color} {
            background: $color;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles split @each headers with an in continuation line', function () {
        $sass = <<<'SASS'
        @each
          $color
          in red, green, blue
            .#{$color}
              background: $color
        SASS;

        $expected = <<<'SCSS'
        @each $color in red, green, blue {
            .#{$color} {
              background: $color;
            }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles @while loops', function () {
        $sass = <<<'SASS'
        $i: 6
        @while $i > 0
          .col-#{$i}
            width: $i * 10%
          $i: $i - 2
        SASS;

        $expected = <<<'SCSS'
        $i: 6;
        @while $i > 0 {
          .col-#{$i} {
            width: $i * 10%;
          }
          $i: $i - 2;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles placeholder selectors', function () {
        $sass = <<<'SASS'
        %button-base
          padding: 10px
          border: none

        .btn
          @extend %button-base
          color: blue
        SASS;

        $expected = <<<'SCSS'
        %button-base {
          padding: 10px;
          border: none;
        }

        .btn {
          @extend %button-base;
          color: blue;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles attribute selectors', function () {
        $sass = <<<'SASS'
        [data-active]
          color: green
          &[data-type="primary"]
            font-weight: bold
        SASS;

        $expected = <<<'SCSS'
        [data-active] {
          color: green;
          &[data-type="primary"] {
            font-weight: bold;
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles @charset directive', function () {
        $sass = <<<'SASS'
        @charset "UTF-8"

        .box
          content: "→"
        SASS;

        $expected = <<<'SCSS'
        @charset "UTF-8";

        .box {
          content: "→";
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles complex nesting with multiple features', function () {
        $sass = <<<'SASS'
        =flex-center
          display: flex
          align-items: center
          justify-content: center

        .container
          +flex-center
          padding: 20px

          .header
            font:
              size: 24px
              weight: bold

            @media (max-width: 768px)
              font:
                size: 18px

          .content
            // Inner comment
            color: #333

            &:hover
              color: #000
        SASS;

        $expected = <<<'SCSS'
        @mixin flex-center {
          display: flex;
          align-items: center;
          justify-content: center;
        }

        .container {
          @include flex-center;
          padding: 20px;
          .header {
            font: {
              size: 24px;
              weight: bold;
            }
            @media (max-width: 768px) {
              font: {
                size: 18px;
              }
            }
          }
          .content {
            // Inner comment
            color: #333;
            &:hover {
              color: #000;
            }
          }
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('handles pseudo-classes as block headers', function () {
        $sass = <<<'SASS'
        body:hover
          color: red

        body:active
          background: blue
        SASS;

        $expected = <<<'SCSS'
        body:hover {
          color: red;
        }

        body:active {
          background: blue;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('continues scanning pseudo-classes after selector-like false positives', function () {
        $sass = <<<'SASS'
        .item:hoverable:hover
          color: red
        SASS;

        $expected = <<<'SCSS'
        .item:hoverable:hover {
          color: red;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    it('continues scanning non-prefixed pseudo-classes after false positives', function () {
        $sass = <<<'SASS'
        body:hoverable:hover
          color: red
        SASS;

        $expected = <<<'SCSS'
        body:hoverable:hover {
          color: red;
        }
        SCSS;

        expect($this->normalizer->normalize($sass))->toBe($expected);
    });

    describe('Line Ending Detection', function () {
        it('detects Windows line endings (CRLF)', function () {
            $sass = ".container\r\n  color: red\r\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->not->toBeEmpty()
                ->and($result)->toContain('.container {')
                ->and($result)->toContain('color: red;');
        });

        it('detects Mac line endings (CR)', function () {
            $sass = ".container\r  color: red\r";

            $result = $this->normalizer->normalize($sass);

            expect($result)->toContain("\r")
                ->and($result)->not->toContain("\n")
                ->and($result)->not->toContain("\r\n");
        });

        it('detects Unix line endings (LF)', function () {
            $sass = ".container\n  color: red\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->toContain("\n")
                ->and($result)->not->toContain("\r")
                ->and($result)->not->toContain("\r\n");
        });

        it('preserves original line ending style', function () {
            $sassWithLF   = ".container\n  color: red\n";
            $sassWithCRLF = ".container\r\n  color: red\r\n";

            $resultLF   = $this->normalizer->normalize($sassWithLF);
            $resultCRLF = $this->normalizer->normalize($sassWithCRLF);

            expect($resultLF)->not->toBeEmpty()
                ->and($resultCRLF)->not->toBeEmpty()
                ->and($resultLF)->toContain('.container {')
                ->and($resultCRLF)->toContain('.container {');
        });

        it('handles mixed line endings gracefully', function () {
            $sass = ".container\r\n  color: red\n  .nested\r    margin: 10px\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->toContain("\r\n");
        });
    });

    describe('Indentation Edge Cases', function () {
        it('handles files with no indentation', function () {
            $sass = ".container\ncolor: red\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->not->toBeEmpty()
                ->and($result)->toContain('.container {')
                ->and($result)->toContain('color: red;');
        });

        it('handles mixed indentation (spaces and tabs)', function () {
            $sass = ".container\n\tcolor: red\n  .nested\n    margin: 10px\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->toContain('.container {')
                ->and($result)->toContain('color: red;')
                ->and($result)->toContain('.nested {')
                ->and($result)->toContain('margin: 10px;');
        });

        it('detects correct indent size automatically', function () {
            $sass = ".container\n    color: red\n      .nested\n        margin: 10px\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->not->toBeEmpty()
                ->and($result)->toContain('.container {')
                ->and($result)->toContain('.nested {')
                ->and($result)->toContain('color: red;')
                ->and($result)->toContain('margin: 10px;');
        });

        it('handles very deep nesting levels', function () {
            $sass = str_repeat('  ', 20) . ".deep\n" . str_repeat('  ', 21) . "color: red\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->toContain('.deep {')
                ->and($result)->toContain('color: red;');
        });

        it('handles single space indentation', function () {
            $sass = ".container\n color: red\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->not->toBeEmpty()
                ->and($result)->toContain('.container {')
                ->and($result)->toContain('color: red;');
        });
    });

    describe('Error Handling and Malformed Input', function () {
        it('processes invalid directives without breaking', function () {
            $invalidSass = ".container\n  @invalid-directive\n  color: red\n";

            $result = $this->normalizer->normalize($invalidSass);

            expect($result)->not->toBeEmpty()
                ->and($result)->toContain('.container {')
                ->and($result)->toContain('color: red;');
        });

        it('handles empty input files', function () {
            $result = $this->normalizer->normalize('');

            expect($result)->toBe('');
        });

        it('handles whitespace-only input', function () {
            $result = $this->normalizer->normalize("   \n\t  \n  ");

            expect($result)->toBe('');
        });

        it('throws for unclosed parentheses in indented sass', function () {
            $malformed = ".grid\n  color: rgb(255, 0, 0\n";

            expect(fn() => $this->normalizer->normalize($malformed))
                ->toThrow(InvalidSyntaxException::class, "Expected closing ')' for '(' opened at line 2.");
        });

        it('throws for unexpected closing parentheses in indented sass', function () {
            $malformed = ".grid\n  color: red)\n";

            expect(fn() => $this->normalizer->normalize($malformed))
                ->toThrow(InvalidSyntaxException::class, "Unexpected ')' at line 2.");
        });

        it('throws for unterminated strings in indented sass', function () {
            $malformed = ".grid\n  content: \"red\n";

            expect(fn() => $this->normalizer->normalize($malformed))
                ->toThrow(InvalidSyntaxException::class, 'Unterminated string starting at line 2.');
        });

        it('throws for unterminated multiline comments in indented sass', function () {
            $malformed = ".grid\n  /* comment\n";

            expect(fn() => $this->normalizer->normalize($malformed))
                ->toThrow(InvalidSyntaxException::class, 'Unterminated comment starting at line 2.');
        });
    });

    describe('Performance and Large Files', function () {
        it('processes large files efficiently', function () {
            $largeSass = str_repeat(".container\n  color: red\n", 1000);
            $startTime = microtime(true);
            $result    = $this->normalizer->normalize($largeSass);
            $endTime   = microtime(true);

            expect($endTime - $startTime)->toBeLessThan(0.5)
                ->and(substr_count($result, '.container {'))->toBe(1000);
        });

        it('maintains performance with Unicode content', function () {
            $unicodeSass = ".container\n  content: " . str_repeat('→←↑↓', 100) . "\n"
                . ".text\n  content: 'класс'\n";

            $startTime = microtime(true);
            $result    = $this->normalizer->normalize($unicodeSass);
            $endTime   = microtime(true);

            expect($endTime - $startTime)->toBeLessThan(0.1)
                ->and($result)->toContain('→←↑↓')
                ->and($result)->toContain('класс');
        });
    });

    describe('Unicode and Special Characters', function () {
        it('handles Unicode characters correctly', function () {
            $sass = ".container\n  content: \"test\"\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->not->toBeEmpty()
                ->and($result)->toContain('.container {')
                ->and($result)->toContain('content: "test";');
        });

        it('handles special characters in content', function () {
            $sass = ".container\n  content: \"→←↑↓\"\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->toContain('content: "→←↑↓";');
        });

        it('handles emoji in selectors', function () {
            $sass = ".🚀\n  color: gold\n";

            $result = $this->normalizer->normalize($sass);

            expect($result)->toContain('.🚀 {')
                ->and($result)->toContain('color: gold;');
        });
    });

    describe('Comments with empty lines', function () {
        it('preserves empty lines before multiline comments', function () {
            $sass = <<<'SASS'

            /* This is a
               multiline comment */
            .box
              color: red
            SASS;

            $expected = <<<'SCSS'

            /* This is a
               multiline comment */
            .box {
              color: red;
            }
            SCSS;

            expect($this->normalizer->normalize($sass))->toBe($expected);
        });

        it('preserves empty lines before single-line comments', function () {
            $sass = <<<'SASS'

            // This is a single line comment
            .box
              color: red
            SASS;

            $expected = <<<'SCSS'

            // This is a single line comment
            .box {
              color: red;
            }
            SCSS;

            expect($this->normalizer->normalize($sass))->toBe($expected);
        });

        it('preserves multiple empty lines before multiline comments', function () {
            $sass = <<<'SASS'


            /* Multiline
               comment */
            .container
              padding: 10px
            SASS;

            $expected = <<<'SCSS'


            /* Multiline
               comment */
            .container {
              padding: 10px;
            }
            SCSS;

            expect($this->normalizer->normalize($sass))->toBe($expected);
        });

        it('preserves multiple empty lines before single-line comments', function () {
            $sass = <<<'SASS'


            // Single line comment
            .container
              padding: 10px
            SASS;

            $expected = <<<'SCSS'


            // Single line comment
            .container {
              padding: 10px;
            }
            SCSS;

            expect($this->normalizer->normalize($sass))->toBe($expected);
        });
    });
});
