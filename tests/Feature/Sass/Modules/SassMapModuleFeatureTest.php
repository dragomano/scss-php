<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Tests\ArrayLogger;

describe('Sass Map Module Feature', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('map.deep-merge()', function () {
        it('merges nested maps deeply', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            @use "sass:meta";
            .map-deep-merge { --value: #{meta.inspect(map.deep-merge((a: (x: 1, y: 2)), (a: (y: 20, z: 30))))}; }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-deep-merge {
              --value: (a: (x: 1, y: 20, z: 30));
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.deep-remove()', function () {
        it('removes nested key from map', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            @use "sass:meta";
            .map-deep-remove { --value: #{meta.inspect(map.deep-remove((a: (x: 1, y: 2), b: 3), a, y))}; }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-deep-remove {
              --value: (a: (x: 1), b: 3);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.get()', function () {
        it('returns value at nested key path', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            $config: (theme: (primary: #112233, accent: #445566));
            .map-get { value: map.get($config, theme, primary); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-get {
              value: #123;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.has-key()', function () {
        it('returns true for nested existing key', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            $config: (theme: (primary: #112233, accent: #445566));
            .map-has-key { value: map.has-key($config, theme, accent); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-has-key {
              value: true;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.keys()', function () {
        it('returns all keys as comma list', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            .map-keys { value: map.keys((theme: dark, size: md)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-keys {
              value: theme, size;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.merge()', function () {
        it('merges two maps with override', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            @use "sass:meta";
            .map-merge { --value: #{meta.inspect(map.merge((a: 1, b: 2), (b: 20, c: 3)))}; }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-merge {
              --value: (a: 1, b: 20, c: 3);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.remove()', function () {
        it('removes multiple keys from map', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            @use "sass:meta";
            .map-remove { --value: #{meta.inspect(map.remove((a: 1, b: 2, c: 3), b, c))}; }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-remove {
              --value: (a: 1);
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.set()', function () {
        it('sets nested key value', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            @use "sass:meta";
            .map-set { --value: #{meta.inspect(map.set((a: (x: 1)), a, y, 2))}; }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-set {
              --value: (a: (x: 1, y: 2));
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('map.values()', function () {
        it('returns all values as comma list', function () {
            $scss = <<<'SCSS'
            @use "sass:map";
            .map-values { value: map.values((theme: dark, size: md)); }
            SCSS;

            $css = $this->compiler->compileString($scss);

            $expected = /** @lang text */ <<<'CSS'
            .map-values {
              value: dark, md;
            }
            CSS;

            expect($css)->toEqualCss($expected);
        });
    });

    describe('global map functions', function () {
        describe('map-get()', function () {
            it('returns value for given key', function () {
                $scss = <<<'SCSS'
                .map-global-get { value: map-get((a: 1, b: 2), b); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .map-global-get {
                  value: 2;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('map-has-key()', function () {
            it('returns true for existing key', function () {
                $scss = <<<'SCSS'
                .map-global-has-key { value: map-has-key((a: 1, b: 2), b); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .map-global-has-key {
                  value: true;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('map-keys()', function () {
            it('returns all keys as comma list', function () {
                $scss = <<<'SCSS'
                .map-global-keys { value: map-keys((theme: dark, size: md)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .map-global-keys {
                  value: theme, size;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('map-merge()', function () {
            it('merges two flat maps', function () {
                $scss = <<<'SCSS'
                @use "sass:meta";
                .map-global-merge { --value: #{meta.inspect(map-merge((a: 1, b: 2), (b: 20, c: 3)))}; }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .map-global-merge {
                  --value: (a: 1, b: 20, c: 3);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('map-remove()', function () {
            it('removes key from map', function () {
                $scss = <<<'SCSS'
                @use "sass:meta";
                .map-global-remove { --value: #{meta.inspect(map-remove((a: 1, b: 2, c: 3), b))}; }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .map-global-remove {
                  --value: (a: 1, c: 3);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('map-set()', function () {
            it('sets key value in map', function () {
                $scss = <<<'SCSS'
                @use "sass:meta";
                .map-global-set { --value: #{meta.inspect(map-set((a: 1, b: 2), b, 99))}; }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .map-global-set {
                  --value: (a: 1, b: 99);
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });

        describe('map-values()', function () {
            it('returns all values as comma list', function () {
                $scss = <<<'SCSS'
                .map-global-values { value: map-values((theme: dark, size: md)); }
                SCSS;

                $css = $this->compiler->compileString($scss);

                $expected = /** @lang text */ <<<'CSS'
                .map-global-values {
                  value: dark, md;
                }
                CSS;

                expect($css)->toEqualCss($expected);
            });
        });
    });

    it('logs deprecations', function () {
        $scss = <<<'SCSS'
        $font-weights: ("regular": 400, "medium": 500, "bold": 700);
        $light-weights: ("lightest": 100, "light": 300);
        $heavy-weights: ("medium": 500, "bold": 700);
        @debug map-get($font-weights, "extra-bold");
        @debug map-has-key($font-weights, "bolder");
        @debug map-keys($font-weights);
        @debug map-merge($light-weights, $heavy-weights);
        @debug map-remove($font-weights, "regular", "bold");
        @debug map-set($font-weights, "regular", 300);
        @debug map-values($font-weights);
        SCSS;

        $this->compiler->compileString($scss);

        expect($this->logger->records)->toHaveCount(14)
            ->and($this->logger->records[0]['message'])->toContain('map-get() is deprecated. Suggestion: map.get($font-weights, "extra-bold")')
            ->and($this->logger->records[1]['message'])->toContain('null')
            ->and($this->logger->records[2]['message'])->toContain('map-has-key() is deprecated. Suggestion: map.has-key($font-weights, "bolder")')
            ->and($this->logger->records[3]['message'])->toContain('false')
            ->and($this->logger->records[4]['message'])->toContain('map-keys() is deprecated. Suggestion: map.keys($font-weights)')
            ->and($this->logger->records[5]['message'])->toContain('"regular", "medium", "bold"')
            ->and($this->logger->records[6]['message'])->toContain('map-merge() is deprecated. Suggestion: map.merge($light-weights, $heavy-weights)')
            ->and($this->logger->records[7]['message'])->toContain('("lightest": 100, "light": 300, "medium": 500, "bold": 700)')
            ->and($this->logger->records[8]['message'])->toContain('map-remove() is deprecated. Suggestion: map.remove($font-weights, "regular", "bold")')
            ->and($this->logger->records[9]['message'])->toContain('("medium": 500)')
            ->and($this->logger->records[10]['message'])->toContain('map-set() is deprecated. Suggestion: map.set($font-weights, "regular", 300)')
            ->and($this->logger->records[11]['message'])->toContain('("regular": 300, "medium": 500, "bold": 700)')
            ->and($this->logger->records[12]['message'])->toContain('map-values() is deprecated. Suggestion: map.values($font-weights)')
            ->and($this->logger->records[13]['message'])->toContain('400, 500, 700');
    });
});
