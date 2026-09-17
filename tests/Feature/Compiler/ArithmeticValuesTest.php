<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\IncompatibleUnitsException;
use Bugo\SCSS\Exceptions\UndefinedOperationException;
use Bugo\SCSS\Syntax;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

        it('formats scientific notation numbers in declarations', function () {
            $scss = <<<'SASS'
            .test {
              wide: 5.2e3;
              tiny: 6e-2;
            }
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              wide: 5200;
              tiny: 0.06;
            }
            CSS;

            $css = $this->compiler->compileString($scss, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('keeps calc wrapper for compound units in declarations', function () {
            $scss = <<<'SASS'
            @use 'sass:math';

            $degrees-per-second: math.div(20deg, 1s);

            .test {
              area: 4px * 6px;
              velocity: math.div(5px, 2s);
              complex: 5px * math.div(math.div(30deg, 2s), 24em);
              ratio: $degrees-per-second;
              inverse: math.div(1, $degrees-per-second);
            }
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              area: calc(24px * 1px);
              velocity: calc(2.5px / 1s);
              complex: calc(3.125px * 1deg / 1s / 1em);
              ratio: calc(20deg / 1s);
              inverse: calc(0.05s / 1deg);
            }
            CSS;

            $css = $this->compiler->compileString($scss, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });

        it('throws for incompatible units in arithmetic expressions', function () {
            $scss = <<<'SASS'
            .test {
              bad: 1in + 1s;
            }
            SASS;

            expect(fn() => $this->compiler->compileString($scss, Syntax::SASS))
                ->toThrow(IncompatibleUnitsException::class, '1in and 1s have incompatible units.');
        });

        it('throws for sassscript arithmetic with calculation values', function () {
            $scss = <<<'SASS'
            $width: calc(100% + 10px);

            .test {
              bad: $width * 2;
            }
            SASS;

            expect(fn() => $this->compiler->compileString($scss, Syntax::SASS))
                ->toThrow(UndefinedOperationException::class, 'Undefined operation "calc(100% + 10px) * 2".');
        });

        it('evaluates modulo inside legacy max() function calls', function () {
            $scss = <<<'SCSS'
            $padding: 12px;
            .post {
              padding-left: max($padding, env(safe-area-inset-left));
              padding-right: max($padding, env(safe-area-inset-right));
            }
            .sidebar {
              padding-left: max($padding % 10, 20px);
              padding-right: max($padding % 10, 20px);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .post {
              padding-left: max(12px, env(safe-area-inset-left));
              padding-right: max(12px, env(safe-area-inset-right));
            }

            .sidebar {
              padding-left: 20px;
              padding-right: 20px;
            }
            CSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates modulo inside legacy min abs and round function calls', function () {
            $scss = <<<'SCSS'
            $padding: 12px;
            .test {
              min-value: min($padding % 10, 5px);
              abs-value: abs(-$padding % 10);
              round-value: round($padding % 10);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              min-value: 2px;
              abs-value: 8px;
              round-value: 2px;
            }
            CSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates arithmetic segment after identifier token in declaration values', function () {
            $scss = <<<'SASS'
            @use 'sass:math';

            $transition-speed: math.div(1s, 50px);

            .slider {
              transition: left (120px - 10px) * $transition-speed;
            }
            SASS;

            $expected = /** @lang text */ <<<'CSS'
            .slider {
              transition: left 2.2s;
            }
            CSS;

            $css = $this->compiler->compileString($scss, Syntax::SASS);

            expect($css)->toEqualCss($expected);
        });
    });
});
