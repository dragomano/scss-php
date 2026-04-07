<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Style;
use Bugo\SCSS\Syntax;
use Tests\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('logs @debug and @warn messages via psr logger', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            .test {
              @debug hello;
              @warn careful;
              color: red;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: red;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected)
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['level'])->toBe('debug')
                ->and($this->logger->records[0]['message'])->toBe('hello')
                ->and($this->logger->records[0]['context']['directive'] ?? null)->toBe('debug')
                ->and($this->logger->records[0]['context']['file'] ?? null)->toBe('input.scss')
                ->and($this->logger->records[0]['context']['line'] ?? null)->toBe(2)
                ->and($this->logger->records[0]['context']['column'] ?? null)->toBeGreaterThan(0)
                ->and($this->logger->records[1]['level'])->toBe('warning')
                ->and($this->logger->records[1]['message'])->toBe('careful')
                ->and($this->logger->records[1]['context']['directive'] ?? null)->toBe('warn')
                ->and($this->logger->records[1]['context']['file'] ?? null)->toBe('input.scss')
                ->and($this->logger->records[1]['context']['line'] ?? null)->toBe(3)
                ->and($this->logger->records[1]['context']['column'] ?? null)->toBeGreaterThan(0);
        });

        it('evaluates inline if() expressions in @debug messages', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @use 'sass:meta';
            $hungry: true;
            @debug if(sass($hungry): breakfast burrito; else: cereal);
            @debug if(not sass($hungry): skip lunch);
            @debug if(sass(meta.variable-exists("thirsty")): thirsty; else: hungry);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(3)
                ->and($this->logger->records[0]['level'])->toBe('debug')
                ->and($this->logger->records[0]['message'])->toBe('breakfast burrito')
                ->and($this->logger->records[1]['level'])->toBe('debug')
                ->and($this->logger->records[1]['message'])->toBe('null')
                ->and($this->logger->records[2]['level'])->toBe('debug')
                ->and($this->logger->records[2]['message'])->toBe('hungry');
        });

        it('partially evaluates inline if() conditions when sass and css conditions are mixed', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SASS'
            $support-widescreen: true;
            @debug if(
              sass($support-widescreen) and media(width >= 3000px): big;
              else: small
            );

            $support-widescreen: false;
            @debug if(
              sass($support-widescreen) and media(width >= 3000px): big;
              else: small
            );
            SASS;

            $css = $compiler->compileString($source, Syntax::SASS);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('if(media(width >= 3000px): big; else: small)')
                ->and($this->logger->records[1]['message'])->toBe('small');
        });

        it('applies sass number precision for debug output and comparison', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug 0.012345678912345;
            @debug 0.01234567891 == 0.012345678912;
            @debug 1.00000000009;
            @debug 0.99999999991;
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(4)
                ->and($this->logger->records[0]['message'])->toBe('.0123456789')
                ->and($this->logger->records[1]['message'])->toBe('true')
                ->and($this->logger->records[2]['message'])->toBe('1.0000000001')
                ->and($this->logger->records[3]['message'])->toBe('.9999999999');
        });

        it('logs @debug directives in sass syntax with inline comments', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SASS'
            @debug 2; // first
            @debug "test"; // second
            SASS;

            $css = $compiler->compileString($source, Syntax::SASS);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('2')
                ->and($this->logger->records[1]['message'])->toBe('test');
        });

        it('keeps quoted string case conversion unquoted in @debug output while preserving CSS quotes', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @use "sass:string";

            .test {
              upper: string.to-upper-case("hello");
              lower: string.to-lower-case("HELLO");
            }

            @debug string.to-upper-case("hello");
            @debug string.to-lower-case("HELLO");
            @debug to-upper-case("ab");
            @debug to-lower-case("AB");
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              upper: "HELLO";
              lower: "hello";
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected)
                ->and($this->logger->records)->toHaveCount(6)
                ->and($this->logger->records[0]['message'])->toBe('HELLO')
                ->and($this->logger->records[0]['context']['line'] ?? null)->toBe(8)
                ->and($this->logger->records[1]['message'])->toBe('hello')
                ->and($this->logger->records[1]['context']['line'] ?? null)->toBe(9)
                ->and($this->logger->records[2]['message'])->toBe('to-upper-case() is deprecated. Suggestion: string.to-upper-case("ab")')
                ->and($this->logger->records[2]['context']['line'] ?? null)->toBe(10)
                ->and($this->logger->records[3]['message'])->toBe('AB')
                ->and($this->logger->records[3]['context']['line'] ?? null)->toBe(10)
                ->and($this->logger->records[4]['message'])->toBe('to-lower-case() is deprecated. Suggestion: string.to-lower-case("AB")')
                ->and($this->logger->records[4]['context']['line'] ?? null)->toBe(11)
                ->and($this->logger->records[5]['message'])->toBe('ab')
                ->and($this->logger->records[5]['context']['line'] ?? null)->toBe(11);
        });

        it('keeps unquoted interpolated identifier fragments in @debug output', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug bold;
            @debug -webkit-flex;
            @debug --123;
            $prefix: ms;
            @debug -#{$prefix}-flex;
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(4)
                ->and($this->logger->records[0]['message'])->toBe('bold')
                ->and($this->logger->records[1]['message'])->toBe('-webkit-flex')
                ->and($this->logger->records[2]['message'])->toBe('--123')
                ->and($this->logger->records[3]['message'])->toBe('-ms-flex');
        });

        it('handles missing color channels for mix and to-space debug output', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @use 'sass:color';

            $grey: hsl(none 0% 50%);

            @debug color.mix($grey, blue, $method: hsl);
            @debug color.to-space($grey, lch);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('hsl(240, 50%, 50%)')
                ->and($this->logger->records[1]['message'])->toBe('lch(53.3889647411% 0 none)');
        });

        it('keeps unicode range token with dash as unquoted string', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug U+0-7F;
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['message'])->toBe('U+0-7F');
        });

        it('formats lists in @debug output', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug (1, 2, 3);
            @debug (a b c);
            @debug [x, y];
            SCSS;

            $compiler->compileString($source);

            expect($this->logger->records)->toHaveCount(3)
                ->and($this->logger->records[0]['message'])->toBe('1, 2, 3')
                ->and($this->logger->records[1]['message'])->toBe('a b c')
                ->and($this->logger->records[2]['message'])->toBe('[x, y]');
        });

        it('formats maps in @debug output', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug ("a": 1, "b": 2);
            SCSS;

            $compiler->compileString($source);

            expect($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['message'])->toBe('("a": 1, "b": 2)');
        });

        it('formats booleans in @debug output', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug true;
            @debug false;
            SCSS;

            $compiler->compileString($source);

            expect($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('true')
                ->and($this->logger->records[1]['message'])->toBe('false');
        });

        it('converts named colors to hex in @debug output in compressed style', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(style: Style::COMPRESSED, verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug red;
            @debug (1px 2px blue);
            @debug (red, green, blue);
            SCSS;

            $compiler->compileString($source);

            expect($this->logger->records)->toHaveCount(3)
                ->and($this->logger->records[0]['message'])->toBe('#f00')
                ->and($this->logger->records[1]['message'])->toBe('1px 2px #00f')
                ->and($this->logger->records[2]['message'])->toBe('#f00, #008000, #00f');
        });

        it('logs empty message for @warn when inline if() resolves to null', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            $hungry: true;
            @warn if(not sass($hungry): skip lunch);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('warning')
                ->and($this->logger->records[0]['message'])->toBe('');
        });

        it('logs and throws for @error directive', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );
            $caught = false;

            try {
                $compiler->compileString('@error fatal;');
            } catch (SassErrorException $e) {
                $caught = true;

                expect($e->getMessage())->toBe('@error: fatal')
                    ->and($e->sourceFile)->toBe('input.scss')
                    ->and($e->sourceLine)->toBe(1)
                    ->and($e->sourceColumn)->toBe(1);
            }

            expect($caught)->toBeTrue()
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('error')
                ->and($this->logger->records[0]['message'])->toBe('fatal')
                ->and($this->logger->records[0]['context']['directive'] ?? null)->toBe('error')
                ->and($this->logger->records[0]['context']['file'] ?? null)->toBe('input.scss')
                ->and($this->logger->records[0]['context']['line'] ?? null)->toBe(1)
                ->and($this->logger->records[0]['context']['column'] ?? null)->toBe(1);
        });

        it('supports @debug in user-defined functions', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @function value($x) {
              @debug in-fn;
              @return $x;
            }
            .test {
              result: value(3);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              result: 3;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected)
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('debug')
                ->and($this->logger->records[0]['message'])->toBe('in-fn');
        });

        it('logs @debug and @warn messages via psr logger in default mode', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SCSS'
            .test {
              @debug hello;
              @warn careful;
              color: red;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: red;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected)
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['level'])->toBe('debug')
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> hello')
                ->and($this->logger->records[0]['context'])->toBe([])
                ->and($this->logger->records[1]['level'])->toBe('warning')
                ->and($this->logger->records[1]['message'])->toBe('input.scss:3 >>> careful')
                ->and($this->logger->records[1]['context'])->toBe([]);
        });

        it('evaluates inline if() expressions in @debug messages in default mode', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SCSS'
            @use 'sass:meta';
            $hungry: true;
            @debug if(sass($hungry): breakfast burrito; else: cereal);
            @debug if(not sass($hungry): skip lunch);
            @debug if(sass(meta.variable-exists("thirsty")): thirsty; else: hungry);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(3)
                ->and($this->logger->records[0]['level'])->toBe('debug')
                ->and($this->logger->records[0]['message'])->toBe('input.scss:3 >>> breakfast burrito')
                ->and($this->logger->records[1]['level'])->toBe('debug')
                ->and($this->logger->records[1]['message'])->toBe('input.scss:4 >>> null')
                ->and($this->logger->records[2]['level'])->toBe('debug')
                ->and($this->logger->records[2]['message'])->toBe('input.scss:5 >>> hungry');
        });

        it('partially evaluates inline if() conditions when sass and css conditions are mixed in default mode', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SASS'
            $support-widescreen: true;
            @debug if(
              sass($support-widescreen) and media(width >= 3000px): big;
              else: small
            );

            $support-widescreen: false;
            @debug if(
              sass($support-widescreen) and media(width >= 3000px): big;
              else: small
            );
            SASS;

            $css = $compiler->compileString($source, Syntax::SASS);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> if(media(width >= 3000px): big; else: small)')
                ->and($this->logger->records[1]['message'])->toBe('input.scss:5 >>> small');
        });

        it('logs @debug directives in sass syntax with inline comments in default mode', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SASS'
            @debug 2; // first
            @debug "test"; // second
            SASS;

            $css = $compiler->compileString($source, Syntax::SASS);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('input.scss:1 >>> 2')
                ->and($this->logger->records[1]['message'])->toBe('input.scss:2 >>> test');
        });

        it('logs empty message for @warn when inline if() resolves to null in default mode', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SCSS'
            $hungry: true;
            @warn if(not sass($hungry): skip lunch);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('warning')
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> ')
                ->and($this->logger->records[0]['context'])->toBe([]);
        });

        it('emits deprecation warning for legacy Sass if() comma syntax', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SCSS'
            $x: true;
            @debug if(true, 10px, 15px);
            @debug if($x, red, blue);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(4)
                ->and($this->logger->records[0]['level'])->toBe('warning')
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> The Sass if() syntax is deprecated in favor of the modern CSS syntax. Use `if(sass(true): 10px; else: 15px)` instead.')
                ->and($this->logger->records[1]['level'])->toBe('debug')
                ->and($this->logger->records[1]['message'])->toBe('input.scss:2 >>> 10px')
                ->and($this->logger->records[2]['level'])->toBe('warning')
                ->and($this->logger->records[2]['message'])->toBe('input.scss:3 >>> The Sass if() syntax is deprecated in favor of the modern CSS syntax. Use `if(sass($x): red; else: blue)` instead.')
                ->and($this->logger->records[3]['level'])->toBe('debug')
                ->and($this->logger->records[3]['message'])->toBe('input.scss:3 >>> red');
        });

        it('does not emit deprecation warning for modern CSS if() syntax', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SCSS'
            $x: true;
            @debug if(sass($x): 10px; else: 15px);
            @debug if(not sass($x): fallback);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['level'])->toBe('debug')
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> 10px')
                ->and($this->logger->records[1]['level'])->toBe('debug')
                ->and($this->logger->records[1]['message'])->toBe('input.scss:3 >>> null');
        });

        it('logs and throws for @error directive in default mode', function () {
            $compiler = new Compiler(logger: $this->logger);
            $caught = false;

            try {
                $compiler->compileString('@error fatal;');
            } catch (SassErrorException $e) {
                $caught = true;

                expect($e->getMessage())->toBe('@error: fatal')
                    ->and($e->sourceFile)->toBe('input.scss')
                    ->and($e->sourceLine)->toBe(1)
                    ->and($e->sourceColumn)->toBe(1);
            }

            expect($caught)->toBeTrue()
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('error')
                ->and($this->logger->records[0]['message'])->toBe('input.scss:1 >>> fatal')
                ->and($this->logger->records[0]['context'])->toBe([]);
        });

        it('supports @debug in user-defined functions in default mode', function () {
            $compiler = new Compiler(logger: $this->logger);

            $source = <<<'SCSS'
            @function value($x) {
              @debug in-fn;
              @return $x;
            }
            .test {
              result: value(3);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              result: 3;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected)
                ->and($this->logger->records)->toHaveCount(1)
                ->and($this->logger->records[0]['level'])->toBe('debug')
                ->and($this->logger->records[0]['message'])->toBe('input.scss:2 >>> in-fn')
                ->and($this->logger->records[0]['context'])->toBe([]);
        });

        it('normalizes escapes in unquoted strings', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @use "sass:string";

            @debug \1F46D;
            @debug \21;
            @debug \7Fx;
            @debug string.length(\7Fx);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(4)
                ->and($this->logger->records[0]['message'])->toBe('👭')
                ->and($this->logger->records[1]['message'])->toBe('\!')
                ->and($this->logger->records[2]['message'])->toBe('\7f x')
                ->and($this->logger->records[3]['message'])->toBe('5');
        });

        it('normalizes newline escape in unquoted strings', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @use "sass:string";

            @debug \a;
            @debug string.length(\a);
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(2)
                ->and($this->logger->records[0]['message'])->toBe('\a ')
                ->and($this->logger->records[1]['message'])->toBe('3');
        });

        it('supports special unquoted css-like value forms', function () {
            $compiler = new Compiler(
                options: new CompilerOptions(verboseLogging: true),
                logger: $this->logger,
            );

            $source = <<<'SCSS'
            @debug url(https://example.org);
            @debug U+4??;
            @debug #my-background;
            @debug %;
            @debug !important;
            SCSS;

            $css = $compiler->compileString($source);

            expect($css)->toBe('')
                ->and($this->logger->records)->toHaveCount(5)
                ->and($this->logger->records[0]['message'])->toBe('url(https://example.org)')
                ->and($this->logger->records[1]['message'])->toBe('U+4??')
                ->and($this->logger->records[2]['message'])->toBe('#my-background')
                ->and($this->logger->records[3]['message'])->toBe('%')
                ->and($this->logger->records[4]['message'])->toBe('!important');
        });
    });
});
