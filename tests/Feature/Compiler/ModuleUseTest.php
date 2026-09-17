<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\CannotModifyBuiltInVariableException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\LoadedFile;
use Bugo\SCSS\Loader;
use Bugo\SCSS\LoaderInterface;
use Tests\Support\ArrayLogger;
use Tests\Support\MemoryLoader;
use Tests\Support\RuntimeFactory;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

        it('qualifies CSS from used modules inside imported rules', function () {
            $loader = new MemoryLoader([
                '/_imported.scss'    => '@use "sass:meta"; @use "nested-used"; in-imported { parent: meta.inspect(&); }',
                '/_nested-used.scss' => '@use "sass:meta"; in-used { value: true; parent: meta.inspect(&); plain: meta.inspect(in-used); quoted: meta.inspect("in-used"); }',
            ]);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            outer { @import "imported"; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            outer in-used {
              value: true;
              parent: (in-used,);
              plain: in-used;
              quoted: "in-used";
            }
            outer in-imported {
              parent: (outer in-imported,);
            }
            CSS;

            expect($compiler->compileString($source))->toEqualCss($expected);
        });

        it('compiles external mixins via @use', function () {
            $loader   = new Loader([__DIR__ . '/../../fixtures']);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "_functions.scss";
            .test { @include functions.highlight(blue); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              border: 1px solid blue;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('compiles external functions via @use', function () {
            $loader   = new Loader([__DIR__ . '/../../fixtures']);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "_functions.scss";
            .test { value: functions.double(4); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: 8;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('reuses cached module on repeated @use with same namespace', function () {
            $functionsLoads = 0;

            $inner = new Loader([__DIR__ . '/../../fixtures']);

            $loader = new class ($inner, $functionsLoads) implements LoaderInterface {
                public function __construct(
                    private readonly Loader $inner,
                    public int &$functionsLoads,
                ) {}

                public function addPath(string $path): void
                {
                    $this->inner->addPath($path);
                }

                public function load(string $url, bool $fromImport = false): LoadedFile
                {
                    if ($url === '_functions.scss') {
                        $this->functionsLoads++;
                    }

                    return $this->inner->load($url, $fromImport);
                }
            };

            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "_functions.scss";
            @use "_functions.scss";
            .test { @include functions.highlight(blue); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              border: 1px solid blue;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected)
                ->and($loader->functionsLoads)->toBe(1);
        });

        it('emits CSS from the same @use module only once', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-use-once-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $modulePath = $tmpDir . '/_simple.scss';
            file_put_contents($modulePath, <<<'SCSS'
            code {
              padding: .25em;
              line-height: 0;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "simple";
                @use "simple";
                @use "simple";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                code {
                  padding: 0.25em;
                  line-height: 0;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($modulePath)) {
                    unlink($modulePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('supports @use with configuration', function () {
            $loader   = new Loader([__DIR__ . '/../../fixtures']);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "_configurable.scss" as cfg with ($primary: blue, $gap: 12px);

            .test {
              @include cfg.theme();
              tone: cfg.tone();
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: blue;
              margin: 12px;
              tone: blue;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('emits module css for @use with configuration', function () {
            $loader   = new Loader([__DIR__ . '/../../fixtures']);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "_configurable_with_css.scss" as cfg with ($primary: blue, $gap: 12px);
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .configurable-sample {
              color: blue;
              margin: 12px;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('applies namespaced configure mixin updates to module styles mixin', function () {
            $loader   = new Loader([__DIR__ . '/../../fixtures']);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "_module_with_configure_styles.scss" as code;

            @include code.configure(
              $black: #222,
              $border-radius: 0.1rem
            );

            @include code.styles;
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            code {
              border-radius: 0.1rem;
              box-shadow: 0 0.5rem 1rem rgba(34, 34, 34, 0.15);
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports reassigning module variables through namespace', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-module-reassign-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $basePath     = $tmpDir . '/_base.scss';
            $overridePath = $tmpDir . '/_override.scss';

            file_put_contents($basePath, '$color: red;');
            file_put_contents($overridePath, <<<'SCSS'
            @use 'base';
            base.$color: blue;
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use 'base';
                @use 'override';

                .test {
                  color: base.$color;
                }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .test {
                  color: blue;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($basePath)) {
                    unlink($basePath);
                }

                if (file_exists($overridePath)) {
                    unlink($overridePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('throws when trying to reassign built-in module variables', function () {
            $source = <<<'SCSS'
            @use "sass:math" as math;
            math.$pi: 0;
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(
                    CannotModifyBuiltInVariableException::class,
                    'Cannot modify built-in variable.',
                );
        });

        it('throws when configuring a built-in module via @use', function () {
            $source = <<<'SCSS'
            @use "sass:math" with ($pi: 0);
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(
                    ModuleResolutionException::class,
                    "Built-in module 'sass:math' can't be configured.",
                );
        });

        it('throws when @use appears after a style rule', function () {
            $source = <<<'SCSS'
            .rule { color: red; }
            @use "sass:math";
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(
                    ModuleResolutionException::class,
                    '@use rules must be written before any other rules',
                );
        });

        it('throws when @forward appears after a style rule', function () {
            $loader   = new Loader([__DIR__ . '/../../fixtures']);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            .rule { color: red; }
            @forward "_test.scss";
            SCSS;

            expect(fn() => $compiler->compileString($source))
                ->toThrow(
                    ModuleResolutionException::class,
                    '@forward rules must be written before any other rules',
                );
        });

        it('allows variable declarations between @use rules', function () {
            $source = <<<'SCSS'
            @use "sass:math";
            $base: 10px;
            @use "sass:color";
            .result { width: math.round($base); }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .result {
              width: 10px;
            }
            CSS;

            expect($this->compiler->compileString($source))->toEqualCss($expected);
        });
    });

    describe('module edge cases', function () {
        it('skips privately named and imported members while merging wildcard modules', function () {
            $loader = new MemoryLoader([
                '/dep.scss'    => '$shared: red; @mixin dep-mixin { color: green; } @function dep-fn($v) { @return $v; }',
                '/_theme.scss' => "@use \"dep\" as *;\n@mixin -priv { color: blue; }\n@function -priv-fn() { @return 0; }\n.own { padding: 0; }",
            ]);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "theme" as *;
            SCSS;

            $result = $compiler->compileString($source);

            expect($result)->toContain('.own');
        });

        it('strips incoming configuration prefixes in forwarded modules', function () {
            $loader = new MemoryLoader([
                '/lib.scss'    => "\$accent: blue !default;\n.lib-rule { color: \$accent; }\n",
                '/_theme.scss' => "\$theme-color: pink !default;\n@forward \"lib\" as lib-*;\n",
            ]);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @use "theme" with ($theme-color: red, $lib-accent: green);
            .result { color: theme.$lib-accent; }
            SCSS;

            $result = $compiler->compileString($source);

            expect($result)->toContain('color: green');
        });

        it('throws when a forwarded target cannot be loaded', function () {
            $loader   = new MemoryLoader(['/theme.scss' => "@forward \"missing-lib\";\n"]);
            $compiler = new Compiler(loader: $loader);

            expect(fn() => $compiler->compileString('@use "theme";'))
                ->toThrow(ModuleResolutionException::class);
        });

        it('compiles plain css modules through a branch session', function () {
            $loader  = new MemoryLoader(['/theme.css' => ".a {}\n"]);
            $runtime = RuntimeFactory::createRuntime(loader: $loader);

            expect($runtime->module()->moduleCssInBranch('/theme.css', '/branch'))->toBe('');
        });

        it('throws for @use configuration whose forwarded target cannot be loaded', function () {
            $loader   = new MemoryLoader(['/theme.scss' => "@forward \"missing\";\n"]);
            $compiler = new Compiler(loader: $loader);

            expect(fn() => $compiler->compileString('@use "theme" with ($color: red);'))
                ->toThrow(ModuleResolutionException::class);
        });

        it('throws for @use configuration whose imported css targets cannot provide defaults', function () {
            $loader = new MemoryLoader([
                '/theme.scss' => "@import \"style.css\";\n",
                '/style.css'  => ".a { color: red; }\n",
            ]);
            $compiler = new Compiler(loader: $loader);

            expect(fn() => $compiler->compileString('@use "theme" with ($color: red);'))
                ->toThrow(ModuleResolutionException::class);
        });

        it('throws for @use configuration whose imported sass targets cannot provide defaults', function () {
            $loader   = new MemoryLoader(['/theme.scss' => "@import \"missing-import\";\n"]);
            $compiler = new Compiler(loader: $loader);

            expect(fn() => $compiler->compileString('@use "theme" with ($color: red);'))
                ->toThrow(ModuleResolutionException::class);
        });

        it('stops circular forward chains while collecting configurable defaults', function () {
            $loader = new MemoryLoader([
                '/a.scss' => "@forward \"b\";\n",
                '/b.scss' => "@forward \"a\";\n",
            ]);
            $compiler = new Compiler(loader: $loader);

            expect(fn() => $compiler->compileString('@use "a" with ($color: red);'))
                ->toThrow(ModuleResolutionException::class);
        });
    });
});
