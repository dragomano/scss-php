<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Loader;
use Tests\ArrayLogger;

describe('Sass Meta Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('meta.accepts-content()', function () {
        it('returns true for content-accepting mixin and false for regular mixin', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";

            @mixin card {
              border-radius: 8px;
              overflow: hidden;

              @if meta.content-exists() {
                background: white;
                box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
                @content;
              }
            }

            @mixin simple-card {
              border-radius: 8px;
              background: #f5f5f5;
              padding: 20px;
              text-align: center;
            }

            @function create-card($mixin-ref) {
              @if meta.accepts-content($mixin-ref) {
                @return "This mixin supports custom content";
              } @else {
                @return "This mixin uses the default styling";
              }
            }

            $card-ref: meta.get-mixin("card");
            $simple-ref: meta.get-mixin("simple-card");

            .card-info::before {
              content: create-card($card-ref);
            }

            .simple-info::before {
              content: create-card($simple-ref);
            }

            .actual-card {
              @include card {
                padding: 20px;

                h3 {
                  color: #333;
                }
              }
            }

            .actual-simple {
              @include simple-card;
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .card-info::before {
              content: "This mixin supports custom content";
            }
            .simple-info::before {
              content: "This mixin uses the default styling";
            }
            .actual-card {
              border-radius: 8px;
              overflow: hidden;
              background: white;
              box-shadow: 0 2px 10px rgba(0, 0, 0, .1);
              padding: 20px;
              h3 {
                color: #333;
              }
            }
            .actual-simple {
              border-radius: 8px;
              background: #f5f5f5;
              padding: 20px;
              text-align: center;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.calc-args()', function () {
        it('returns arguments of calc expression', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-calc-args { value: meta.calc-args(calc(100% - 10px)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-calc-args {
              value: 100% - 10px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.calc-name()', function () {
        it('returns function name of calc expression', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-calc-name { value: meta.calc-name(calc(100% - 10px)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-calc-name {
              value: calc;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.call()', function () {
        it('calls list.length via module function reference', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            @use "sass:meta";
            .meta-call {
              value: meta.call(meta.get-function("length", $module: "list"), a b);
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-call {
              value: 2;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('filters list using local function reference', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            @use "sass:meta";
            @use "sass:string";

            @function remove-where($list, $condition) {
              $new-list: ();
              $separator: list.separator($list);

              @each $element in $list {
                @if not meta.call($condition, $element) {
                  $new-list: list.append($new-list, $element, $separator: $separator);
                }
              }

              @return $new-list;
            }

            $fonts: Tahoma, Geneva, "Helvetica Neue", Helvetica, Arial, sans-serif;

            .content {
              @function contains-helvetica($string) {
                @return string.index($string, "Helvetica");
              }

              font-family: remove-where($fonts, meta.get-function("contains-helvetica"));
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .content {
              font-family: Tahoma, Geneva, Arial, sans-serif;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.content-exists()', function () {
        it('returns true with content block and false without', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";

            @mixin card {
              border-radius: 8px;
              padding: 16px;

              @if meta.content-exists() {
                background: white;
                box-shadow: 0 2px 8px rgba(0, 0, 0, 0.1);
                @content;
              } @else {
                background-color: #f0f0f0;
                border: 2px dashed #999;
              }
            }

            .user-card {
              @include card {
                color: #333;
                font-size: 16px;
              }
            }

            .placeholder {
              @include card;
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .user-card {
              border-radius: 8px;
              padding: 16px;
              background: white;
              box-shadow: 0 2px 8px rgba(0, 0, 0, .1);
              color: #333;
              font-size: 16px;
            }
            .placeholder {
              border-radius: 8px;
              padding: 16px;
              background-color: #f0f0f0;
              border: 2px dashed #999;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.feature-exists()', function () {
        it('returns false for unsupported feature name', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-feature { value: meta.feature-exists("x"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-feature {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns true for global-variable-shadowing', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-feature-supported { value: meta.feature-exists("global-variable-shadowing"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-feature-supported {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.function-exists()', function () {
        it('returns true for built-in list function', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-fn-exists { value: meta.function-exists("length"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-fn-exists {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns true for math.div with module arg', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            @use "sass:meta";
            .meta-fn-exists-module { value: meta.function-exists("div", "math"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-fn-exists-module {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws for unknown module namespace', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-fn-exists-module { value: meta.function-exists("div", "math"); }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(ModuleResolutionException::class, "Unknown module namespace 'math'.");
        });
    });

    describe('meta.get-function()', function () {
        it('calls loaded module function by reference', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../../fixtures']));

            $scss = <<<'SCSS'
            @use "sass:map";
            @use "sass:meta";
            @use "code";

            .meta-module-call {
              value: meta.call(map.get(meta.module-functions("code"), "pow"), 3, 4);
            }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            code {
              background-color: #6b717f;
              color: #d2e1dd;
            }
            .meta-module-call {
              value: 81;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.get-mixin()', function () {
        it('returns mixin reference via module-mixins', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../../fixtures']));

            $scss = <<<'SCSS'
            @use "sass:map";
            @use "sass:meta";
            @use "code";

            .meta-module-mixin-type {
              value: meta.type-of(map.get(meta.module-mixins("code"), "stretch"));
            }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            code {
              background-color: #6b717f;
              color: #d2e1dd;
            }
            .meta-module-mixin-type {
              value: mixin;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.global-variable-exists()', function () {
        it('returns false before declaration and true after at global scope', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";

            .meta-global-var-before { value: meta.global-variable-exists("var1"); }

            $var1: value;

            .meta-global-var-after { value: meta.global-variable-exists("var1"); }

            .meta-global-var-local {
              $var2: value;
              value: meta.global-variable-exists("var2");
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-global-var-before {
              value: false;
            }
            .meta-global-var-after {
              value: true;
            }
            .meta-global-var-local {
              value: false;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns true for math module variable with module arg', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:math";
            .meta-global-var-module { value: meta.global-variable-exists("epsilon", "math"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-global-var-module {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws for unknown module namespace', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-global-var-module { value: meta.global-variable-exists("epsilon", "math"); }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(ModuleResolutionException::class, "Unknown module namespace 'math'.");
        });
    });

    describe('meta.inspect()', function () {
        it('serializes map as string', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-inspect { value: meta.inspect((a: 1, b: 2)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-inspect {
              value: (a: 1, b: 2);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.keywords()', function () {
        it('returns named argument map', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-keywords { value: meta.keywords((a: 1)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-keywords {
              value: (a: 1);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.mixin-exists()', function () {
        it('returns false before definition and true after', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";

            .meta-mixin-before { value: meta.mixin-exists("shadow-none"); }

            @mixin shadow-none {
              box-shadow: none;
            }

            .meta-mixin-after { value: meta.mixin-exists("shadow-none"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-mixin-before {
              value: false;
            }
            .meta-mixin-after {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws for unknown module namespace', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-mixin-module { value: meta.mixin-exists("stretch", "code"); }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(ModuleResolutionException::class, "Unknown module namespace 'code'.");
        });
    });

    describe('meta.module-functions()', function () {
        it('logs function reference as get-function() call', function () {
            $compiler = new Compiler(
                loader: new Loader([__DIR__ . '/../../../fixtures']),
                logger: $this->logger
            );

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "code";
            @debug meta.module-functions("code");
            SCSS;

            $compiler->compileString($scss);

            expect($this->logger->records[0]['message'])
                ->toBe('input.scss:3 >>> (pow: get-function("pow"))');
        });

        it('returns map containing known functions', function () {
            $scss = <<<'SCSS'
            @use "sass:list";
            @use "sass:meta";
            .meta-module-fns { value: meta.module-functions("list"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toContain('length');
        });
    });

    describe('meta.module-mixins()', function () {
        it('returns map containing loaded module mixins', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../../fixtures']));

            $scss = <<<'SCSS'
            @use "_functions.scss";
            @use "sass:meta";
            .meta-module-mixins { value: meta.module-mixins("functions"); }
            SCSS;

            $css = $compiler->compileString($scss);

            expect($css)->toContain('highlight');
        });

        it('logs mixin reference as get-mixin() call', function () {
            $compiler = new Compiler(
                loader: new Loader([__DIR__ . '/../../../fixtures']),
                logger: $this->logger
            );

            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "code";
            @debug meta.module-mixins("code");
            SCSS;

            $compiler->compileString($scss);

            expect($this->logger->records[0]['message'])
                ->toBe('input.scss:3 >>> (stretch: get-mixin("stretch"))');
        });
    });

    describe('meta.module-variables()', function () {
        it('returns map containing known variables', function () {
            $scss = <<<'SCSS'
            @use "sass:math";
            @use "sass:meta";
            .meta-module-vars { value: meta.module-variables("math"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            expect($css)->toContain('epsilon');
        });
    });

    describe('meta.type-of()', function () {
        it('returns map for map value', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-type { value: meta.type-of((a: 1)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-type {
              value: map;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.variable-exists()', function () {
        it('returns false before declaration and true in same scope', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";

            .meta-var-before { value: meta.variable-exists("var21"); }

            $var21: value;

            .meta-var-after { value: meta.variable-exists("var21"); }

            .meta-var-local {
              $var23: value;
              value: meta.variable-exists("var23");
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-var-before {
              value: false;
            }
            .meta-var-after {
              value: true;
            }
            .meta-var-local {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('returns true for math module variable with module arg', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            @use "sass:math";
            .meta-var-module { value: meta.variable-exists("epsilon", "math"); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-var-module {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });

        it('throws for unknown module namespace', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";
            .meta-var-module { value: meta.variable-exists("epsilon", "math"); }
            SCSS;

            expect(fn() => $this->compiler->compileString($scss))
                ->toThrow(ModuleResolutionException::class, "Unknown module namespace 'math'.");
        });
    });

    describe('meta.apply()', function () {
        it('includes mixin with named and positional arguments', function () {
            $scss = <<<'SCSS'
            @use "sass:meta";

            @mixin box($width, $height: 10px) {
              width: $width;
              height: $height;
            }

            .meta-apply {
              @include meta.apply(meta.get-mixin("box"), 20px, $height: 30px);
            }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-apply {
              width: 20px;
              height: 30px;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('meta.load-css()', function () {
        it('loads external stylesheet with configuration variable', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../../fixtures']));

            $scss = <<<'SCSS'
            @use "sass:meta";

            .meta-load-css {
              @include meta.load-css("meta-load-css", $with: ("tone": blue));
            }
            SCSS;

            $css = $compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .meta-load-css .loaded-css {
              color: blue;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('global meta functions', function () {
        describe('accepts-content()', function () {
            it('returns false for mixin without @content and true for mixin with @content', function () {
                $scss = <<<'SCSS'
                @mixin simple {}
                @mixin with-content { @content; }
                .accepts-test {
                  a: accepts-content(get-mixin("simple"));
                  b: accepts-content(get-mixin("with-content"));
                }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .accepts-test {
                  a: false;
                  b: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('calc-args()', function () {
            it('returns arguments of calc expression', function () {
                $scss = <<<'SCSS'
                .meta-global-calc-args { value: calc-args(calc(100% - 10px)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-calc-args {
                  value: 100% - 10px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('calc-name()', function () {
            it('returns function name of calc expression', function () {
                $scss = <<<'SCSS'
                .meta-global-calc-name { value: calc-name(calc(100% - 10px)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-calc-name {
                  value: calc;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('call()', function () {
            it('calls global function by reference', function () {
                $scss = <<<'SCSS'
                .meta-global-call { value: call(get-function("length"), a b c); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-call {
                  value: 3;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('content-exists()', function () {
            it('returns false inside mixin without content block', function () {
                $scss = <<<'SCSS'
                @mixin check-content {
                  value: content-exists();
                }
                .meta-global-content { @include check-content; }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-content {
                  value: false;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('feature-exists()', function () {
            it('returns true for global-variable-shadowing', function () {
                $scss = <<<'SCSS'
                .meta-global-feature { value: feature-exists("global-variable-shadowing"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-feature {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('function-exists()', function () {
            it('returns true for built-in list function', function () {
                $scss = <<<'SCSS'
                .meta-global-fn-exists { value: function-exists("length"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-fn-exists {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('get-function()', function () {
            it('returns callable reference for user-defined function', function () {
                $scss = <<<'SCSS'
                @function double($n) { @return $n * 2; }
                .meta-global-get-fn { value: call(get-function("double"), 5px); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-get-fn {
                  value: 10px;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('get-mixin()', function () {
            it('returns mixin type for defined mixin reference', function () {
                $scss = <<<'SCSS'
                @mixin simple { color: red; }
                .meta-global-get-mixin { value: type-of(get-mixin("simple")); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-get-mixin {
                  value: mixin;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('global-variable-exists()', function () {
            it('returns false before declaration and true after', function () {
                $scss = <<<'SCSS'
                .before { value: global-variable-exists("gvar"); }
                $gvar: 1;
                .after { value: global-variable-exists("gvar"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .before {
                  value: false;
                }
                .after {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('inspect()', function () {
            it('serializes map as string', function () {
                $scss = <<<'SCSS'
                .meta-global-inspect { value: inspect((a: 1, b: 2)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-inspect {
                  value: (a: 1, b: 2);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('keywords()', function () {
            it('returns named argument map', function () {
                $scss = <<<'SCSS'
                .meta-global-keywords { value: keywords((a: 1)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-keywords {
                  value: (a: 1);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('mixin-exists()', function () {
            it('returns false before definition and true after', function () {
                $scss = <<<'SCSS'
                .before { value: mixin-exists("my-mix"); }
                @mixin my-mix { color: red; }
                .after { value: mixin-exists("my-mix"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .before {
                  value: false;
                }
                .after {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('module-functions()', function () {
            it('returns map containing known functions', function () {
                $scss = <<<'SCSS'
                @use "sass:list";
                .meta-global-module-fns { value: module-functions("list"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                expect($css)->toContain('length');
            });
        });

        describe('module-mixins()', function () {
            it('returns map containing loaded module mixins', function () {
                $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../../fixtures']));

                $scss = <<<'SCSS'
                @use "_functions.scss";
                .meta-global-module-mixins { value: module-mixins("functions"); }
                SCSS;

                $css = $compiler->compileString($scss);

                expect($css)->toContain('highlight');
            });
        });

        describe('module-variables()', function () {
            it('returns map containing known variables', function () {
                $scss = <<<'SCSS'
                @use "sass:math";
                .meta-global-module-vars { value: module-variables("math"); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                expect($css)->toContain('epsilon');
            });
        });

        describe('type-of()', function () {
            it('returns map for map value', function () {
                $scss = <<<'SCSS'
                .meta-global-type { value: type-of((a: 1)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-type {
                  value: map;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('variable-exists()', function () {
            it('returns true for variable in current scope', function () {
                $scss = <<<'SCSS'
                .meta-global-var {
                  $x: 1;
                  value: variable-exists("x");
                }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .meta-global-var {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });
    });

    it('logs deprecations', function () {
        $scss = <<<'SCSS'
        @use "sass:math";
        @use "sass:meta";

        @debug meta.feature-exists("at-error");
        @debug feature-exists("unrecognized");

        @debug meta.function-exists("div", $module: "math");
        @debug meta.function-exists("scale-color");
        @debug meta.function-exists("add");

        @function add($num1, $num2) {
          @return $num1 + $num2;
        }

        @debug function-exists("add");

        @debug meta.global-variable-exists("var1");
        $var1: value;
        @debug global-variable-exists("var1");

        @debug meta.mixin-exists("shadow-none");

        @mixin shadow-none {
          box-shadow: none;
        }

        @debug mixin-exists("shadow-none");

        @debug meta.variable-exists("var2");
        $var2: value;
        @debug variable-exists("var2");
        SCSS;

        $this->compiler->compileString($scss);

        $messages = implode("\n", array_column($this->logger->records, 'message'));

        expect($this->logger->records)->toHaveCount(17)
            ->and($messages)->toContain('input.scss:4 >>> true')
            ->and($messages)->toContain('feature-exists() is deprecated. Suggestion: meta.feature-exists("unrecognized")')
            ->and($messages)->toContain('input.scss:5 >>> false')
            ->and($messages)->toContain('function-exists() is deprecated. Suggestion: meta.function-exists("add")')
            ->and($messages)->toContain('input.scss:7 >>> true')
            ->and($messages)->toContain('input.scss:8 >>> true')
            ->and($messages)->toContain('input.scss:9 >>> false')
            ->and($messages)->toContain('global-variable-exists() is deprecated. Suggestion: meta.global-variable-exists("var1")')
            ->and($messages)->toContain('mixin-exists() is deprecated. Suggestion: meta.mixin-exists("shadow-none")')
            ->and($messages)->toContain('variable-exists() is deprecated. Suggestion: meta.variable-exists("var2")');
    });
});
