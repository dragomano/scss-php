<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Tests\ArrayLogger;

describe('Sass Math Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('math.abs()', function () {
        it('returns absolute value of negative px', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-abs { value: math.abs(-10px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-abs {
              value: 10px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.acos()', function () {
        it('returns 0deg for acos(1)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-acos { value: math.acos(1); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-acos {
              value: 0deg;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('serializes NaN result as calc() with angle unit', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-acos-nan { value: math.acos(2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-acos-nan {
              value: calc(NaN * 1deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.asin()', function () {
        it('returns 0deg for asin(0)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-asin { value: math.asin(0); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-asin {
              value: 0deg;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('serializes NaN result as calc() with angle unit', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-asin-nan { value: math.asin(2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-asin-nan {
              value: calc(NaN * 1deg);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.atan()', function () {
        it('returns 0deg for atan(0)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-atan { value: math.atan(0); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-atan {
              value: 0deg;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.atan2()', function () {
        it('returns 0deg for atan2(0, 1)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-atan2 { value: math.atan2(0, 1); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-atan2 {
              value: 0deg;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.ceil()', function () {
        it('rounds up to nearest integer', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-ceil { value: math.ceil(1.2px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-ceil {
              value: 2px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.clamp()', function () {
        it('returns middle value when within bounds', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-clamp { value: math.clamp(1, 10, 5); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-clamp {
              value: 5;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.compatible()', function () {
        it('returns true for same-unit numbers', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-compatible { value: math.compatible(1px, 2px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-compatible {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.cos()', function () {
        it('returns 1 for cos(0deg)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-cos { value: math.cos(0deg); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-cos {
              value: 1;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.div()', function () {
        it('divides number keeping unit', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-div { value: math.div(10px, 2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-div {
              value: 5px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.floor()', function () {
        it('rounds down to nearest integer', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-floor { value: math.floor(1.9px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-floor {
              value: 1px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.hypot()', function () {
        it('returns hypotenuse of 3-4-5 triangle', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-hypot { value: math.hypot(3, 4); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-hypot {
              value: 5;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.is-unitless()', function () {
        it('returns true for unitless number', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-is-unitless { value: math.is-unitless(3); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-is-unitless {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.log()', function () {
        it('returns log base 2 of 8', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-log { value: math.log(8, 2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-log {
              value: 3;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.max()', function () {
        it('returns maximum value from list', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-max { value: math.max(1, 4, 2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-max {
              value: 4;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.min()', function () {
        it('returns minimum value from list', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-min { value: math.min(1, 4, 2); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-min {
              value: 1;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws for incompatible units', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-min-strict { value: math.min(10px, 2vw); }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))->toThrow(RuntimeException::class);
        });
    });

    describe('math.percentage()', function () {
        it('converts decimal to percentage', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-percentage { value: math.percentage(0.25); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-percentage {
              value: 25%;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.pow()', function () {
        it('returns 2 to the power of 3', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-pow { value: math.pow(2, 3); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-pow {
              value: 8;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws when either argument has units', function (string $expression) {
            $scss = <<<SCSS
            @use "sass:math";
            .math-pow-invalid { value: {$expression}; }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(MissingFunctionArgumentsException::class, 'a unitless number');
        })->with([
            'unitful base' => ['math.pow(2px, 3)'],
            'unitful exponent' => ['math.pow(2, 3px)'],
        ]);
    });

    describe('math.random()', function () {
        it('returns bounded integer within limit', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-random { value: math.random(1); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-random {
              value: 1;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.round()', function () {
        it('rounds to nearest integer', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-round { value: math.round(1.7px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-round {
              value: 2px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.sin()', function () {
        it('returns 0 for sin(0deg)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-sin { value: math.sin(0deg); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-sin {
              value: 0;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.sqrt()', function () {
        it('returns 3 for sqrt(9)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-sqrt { value: math.sqrt(9); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-sqrt {
              value: 3;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('serializes NaN as calc() for negative input', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-sqrt-nan { value: math.sqrt(-1); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-sqrt-nan {
              value: calc(NaN);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.tan()', function () {
        it('returns 0 for tan(0deg)', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-tan { value: math.tan(0deg); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-tan {
              value: 0;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('math.unit()', function () {
        it('returns unit string for px value', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-unit { value: math.unit(10px); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-unit {
              value: px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('module variables', function () {
        it('epsilon is unitless', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            .math-epsilon { value: math.is-unitless(math.$epsilon); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-epsilon {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('max-safe-integer is accessible via custom namespace alias', function () {
            $scss = <<<'SCSS'
            @use "sass:math" as m;
            .math-vars-alias {
              safe: m.$max-safe-integer;
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-vars-alias {
              safe: 9007199254740991;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('pi is accessible via custom namespace alias', function () {
            $scss = <<<'SCSS'
            @use "sass:math" as m;
            .math-vars-alias {
              pi-round: m.round(m.$pi);
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .math-vars-alias {
              pi-round: 3;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('global math functions', function () {
        describe('abs()', function () {
            it('returns absolute value of negative px', function () {
                $scss = <<<'SCSS'
                .math-global-abs { value: abs(-10px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-abs {
                  value: 10px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('does not emit deprecation warning', function () {
                $scss = <<<'SCSS'
                .math-global-abs-no-warning { value: abs(-10px); }
                SCSS;

                $this->compiler->compileString($scss);

                expect($this->logger->records)->toBe([]);
            });

            it('falls back to native css abs() for calc argument', function () {
                $scss = <<<'SCSS'
                .math-abs-css { value: abs(calc(20px - 2vw)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-abs-css {
                  value: abs(calc(20px - 2vw));
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('acos()', function () {
            it('returns 0deg for acos(1)', function () {
                $scss = <<<'SCSS'
                .math-global-acos { value: acos(1); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-acos {
                  value: 0deg;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('asin()', function () {
            it('returns 0deg for asin(0)', function () {
                $scss = <<<'SCSS'
                .math-global-asin { value: asin(0); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-asin {
                  value: 0deg;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('ceil()', function () {
            it('rounds up to nearest integer', function () {
                $scss = <<<'SCSS'
                .math-global-ceil { value: ceil(1.2px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-ceil {
                  value: 2px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('clamp()', function () {
            it('evaluates with compatible px units', function () {
                $scss = <<<'SCSS'
                .math-global-clamp { value: clamp(1px, 10px, 5px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-clamp {
                  value: 5px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('evaluates with converted comparable units without deprecation', function () {
                $scss = <<<'SCSS'
                .math-global-clamp-converted { value: clamp(-1in, 1cm, 10mm); }
                SCSS;

                $this->compiler->compileString($scss);

                expect($this->logger->records)->toBe([]);
            });

            it('falls back to native css clamp() for mixed units', function () {
                $scss = <<<'SCSS'
                .math-clamp-css { value: clamp(12px, 2vw, 24px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-clamp-css {
                  value: clamp(12px, 2vw, 24px);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('comparable()', function () {
            it('returns true for same-unit numbers', function () {
                $scss = <<<'SCSS'
                .math-global-comparable { value: comparable(10px, 2px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-comparable {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('cos()', function () {
            it('returns 1 for cos(0deg)', function () {
                $scss = <<<'SCSS'
                .math-global-cos { value: cos(0deg); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-cos {
                  value: 1;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('floor()', function () {
            it('rounds down to nearest integer', function () {
                $scss = <<<'SCSS'
                .math-global-floor { value: floor(1.9px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-floor {
                  value: 1px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('hypot()', function () {
            it('returns hypotenuse of 3-4-5 triangle', function () {
                $scss = <<<'SCSS'
                .math-global-hypot { value: hypot(3, 4); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-hypot {
                  value: 5;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('log()', function () {
            it('returns log base 2 of 8', function () {
                $scss = <<<'SCSS'
                .math-global-log { value: log(8, 2); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-log {
                  value: 3;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('max()', function () {
            it('returns maximum from compatible units', function () {
                $scss = <<<'SCSS'
                .math-global-max { value: max(10px, 2px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-max {
                  value: 10px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('evaluates with spread arguments', function () {
                $scss = <<<'SCSS'
                $widths: 50px, 30px, 100px;
                .math-global-max-spread { value: max($widths...); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-max-spread {
                  value: 100px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('falls back to native css max() for calc argument', function () {
                $scss = <<<'SCSS'
                .math-max-css { value: max(50px, calc(20px + 2vw)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-max-css {
                  value: max(50px, 20px + 2vw);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('min()', function () {
            it('returns minimum from compatible units', function () {
                $scss = <<<'SCSS'
                .math-global-min { value: min(10px, 2px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-min {
                  value: 2px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('evaluates with spread arguments', function () {
                $scss = <<<'SCSS'
                $widths: 50px, 30px, 100px;
                .math-global-min-spread { value: min($widths...); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-min-spread {
                  value: 30px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('falls back to native css min() for incompatible units', function () {
                $scss = <<<'SCSS'
                .math-min-css { value: min(10px, 2vw); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-min-css {
                  value: min(10px, 2vw);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('percentage()', function () {
            it('converts decimal to percentage', function () {
                $scss = <<<'SCSS'
                .math-global-percentage { value: percentage(0.25); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-percentage {
                  value: 25%;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('pow()', function () {
            it('returns 2 to the power of 3', function () {
                $scss = <<<'SCSS'
                .math-global-pow { value: pow(2, 3); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-pow {
                  value: 8;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('random()', function () {
            it('returns bounded integer within limit', function () {
                $scss = <<<'SCSS'
                .math-global-random { value: random(1); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-random {
                  value: 1;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('round()', function () {
            it('rounds to nearest integer', function () {
                $scss = <<<'SCSS'
                .math-global-round { value: round(1.7px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-round {
                  value: 2px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });

            it('falls back to native css round() for calc argument', function () {
                $scss = <<<'SCSS'
                .math-round-css { value: round(calc(20px + 2vw)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-round-css {
                  value: round(calc(20px + 2vw));
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('sin()', function () {
            it('returns 0 for sin(0deg)', function () {
                $scss = <<<'SCSS'
                .math-global-sin { value: sin(0deg); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-sin {
                  value: 0;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('sqrt()', function () {
            it('returns 3 for sqrt(9)', function () {
                $scss = <<<'SCSS'
                .math-global-sqrt { value: sqrt(9); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-sqrt {
                  value: 3;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('tan()', function () {
            it('returns 0 for tan(0deg)', function () {
                $scss = <<<'SCSS'
                .math-global-tan { value: tan(0deg); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-tan {
                  value: 0;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('unit()', function () {
            it('returns unit string for px value', function () {
                $scss = <<<'SCSS'
                .math-global-unit { value: unit(10px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-unit {
                  value: px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('unitless()', function () {
            it('returns true for unitless number', function () {
                $scss = <<<'SCSS'
                .math-global-unitless { value: unitless(10); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .math-global-unitless {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });
    });

    it('logs deprecations', function () {
        $scss = <<<'SCSS'
        @debug ceil(1.2px);
        @debug comparable(10px, 2px);
        @debug floor(1.9px);
        @debug percentage(0.25);
        $widths: 50px, 30px, 100px;
        @debug max($widths...);
        @debug min($widths...);
        @debug random(1);
        @debug unit(10px);
        @debug unitless(10);
        SCSS;

        $this->compiler->compileString($scss);

        expect($this->logger->records)->toHaveCount(18)
            ->and($this->logger->records[0]['message'])->toContain('ceil() is deprecated. Suggestion: math.ceil(1.2px)')
            ->and($this->logger->records[1]['message'])->toContain('2px')
            ->and($this->logger->records[2]['message'])->toContain('comparable() is deprecated. Suggestion: math.compatible(10px, 2px)')
            ->and($this->logger->records[3]['message'])->toContain('true')
            ->and($this->logger->records[4]['message'])->toContain('floor() is deprecated. Suggestion: math.floor(1.9px)')
            ->and($this->logger->records[5]['message'])->toContain('1px')
            ->and($this->logger->records[6]['message'])->toContain('percentage() is deprecated. Suggestion: math.percentage(0.25)')
            ->and($this->logger->records[7]['message'])->toContain('25%')
            ->and($this->logger->records[8]['message'])->toContain('max() is deprecated. Suggestion: math.max($widths...)')
            ->and($this->logger->records[9]['message'])->toContain('100px')
            ->and($this->logger->records[10]['message'])->toContain('min() is deprecated. Suggestion: math.min($widths...)')
            ->and($this->logger->records[11]['message'])->toContain('30px')
            ->and($this->logger->records[12]['message'])->toContain('random() is deprecated. Suggestion: math.random(1)')
            ->and($this->logger->records[13]['message'])->toContain('1')
            ->and($this->logger->records[14]['message'])->toContain('unit() is deprecated. Suggestion: math.unit(10px)')
            ->and($this->logger->records[15]['message'])->toContain('px')
            ->and($this->logger->records[16]['message'])->toContain('unitless() is deprecated. Suggestion: math.is-unitless(10)')
            ->and($this->logger->records[17]['message'])->toContain('true');
    });
});
