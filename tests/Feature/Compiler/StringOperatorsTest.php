<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Syntax;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

        it('preserves backslash escapes in quoted strings', function () {
            $source = <<<'SASS'
            $icons: ("eye": "\f112")

            @each $name, $glyph in $icons
              .icon-#{$name}:before
                content: $glyph
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .icon-eye:before {
              content: "\f112";
            }
            CSS;

            $css = $this->compiler->compileString($source, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('decodes astral unicode escapes in quoted strings and emits charset', function () {
            $source = <<<'SCSS'
            @counter-style thumbs {
              system: cyclic;
              symbols: "\1F44D";
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            @charset "UTF-8";
            @counter-style thumbs {
              system: cyclic;
              symbols: "👍";
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('concatenates strings with plus operator in user-defined functions', function () {
            $source = <<<'SCSS'
            @use "sass:string";

            @function str-insert($string, $insert, $index) {
              $before: string.slice($string, 0, $index);
              $after: string.slice($string, $index);
              @return $before + $insert + $after;
            }

            .test {
              value: str-insert('test', '22', 2);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: "te22est";
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('concatenates identifier strings with minus operator', function () {
            $source = <<<'SCSS'
            .test {
              a: sans - serif;
              b: sans- + serif;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              a: sans-serif;
              b: sans-serif;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('concatenates quoted string with number using plus operator', function () {
            $source = <<<'SCSS'
            .test {
              a: "elapsed: " + 10s;
              b: "hello" + 42px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              a: "elapsed: 10s";
              b: "hello42px";
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('collapses number plus unit suffix into a dimension', function () {
            $source = <<<'SCSS'
            $raw-size: 42;

            .debug-suffix-bug {
              width: $raw-size + px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .debug-suffix-bug {
              width: 42px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('keeps unary prefix slash and minus before identifiers', function () {
            $source = <<<'SCSS'
            .test {
              a: - moz;
              b: / 15px;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              a: -moz;
              b: /15px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });

        it('emits a deprecation warning for ambiguous strict unary syntax', function () {
            $source = <<<'SCSS'
            $a: 10px;
            $b: 5px;

            .result {
              margin: $a -$b;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .result {
              margin: 5px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected)
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('warning')
                ->and($this->logger->records[0]['message'])->toContain('This expression will be parsed differently in a future release.')
                ->and($this->logger->records[0]['message'])->toContain('"a - b"')
                ->and($this->logger->records[0]['message'])->toContain('"a (-b)"')
                ->and($this->logger->records[0]['message'])->toContain('input.scss:5 >>>')
                ->and($this->logger->records[0]['context'])->toBe([]);
        });
    });
});
