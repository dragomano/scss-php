<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\CannotModifyBuiltInVariableException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Loader;
use Bugo\SCSS\LoaderInterface;
use Tests\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {
        it('compiles external mixins via @use', function () {
            $loader = new Loader([__DIR__ . '/../../fixtures']);
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
            $loader = new Loader([__DIR__ . '/../../fixtures']);
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

                public function load(string $url, bool $fromImport = false): array
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
                  padding: .25em;
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
            $loader = new Loader([__DIR__ . '/../../fixtures']);
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
            $loader = new Loader([__DIR__ . '/../../fixtures']);
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
            $loader = new Loader([__DIR__ . '/../../fixtures']);
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
              border-radius: .1rem;
              box-shadow: 0 .5rem 1rem rgba(34, 34, 34, .15);
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports reassigning module variables through namespace', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-module-reassign-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $basePath = $tmpDir . '/_base.scss';
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
                ->toThrow(CannotModifyBuiltInVariableException::class, 'Cannot modify built-in variable.');
        });

        it('throws when configuring a built-in module via @use', function () {
            $source = <<<'SCSS'
            @use "sass:math" with ($pi: 0);
            SCSS;

            expect(fn() => $this->compiler->compileString($source))
                ->toThrow(ModuleResolutionException::class, "Built-in module 'sass:math' can't be configured.");
        });

        it('supports @import for css output and exported members', function () {
            $loader = new Loader([__DIR__ . '/../../fixtures']);
            $compiler = new Compiler(loader: $loader);

            $source = <<<'SCSS'
            @import "_imported.scss";
            .test {
              color: $import-color;
              @include imported-border();
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .from-import {
              value: imported;
            }
            .test {
              color: red;
              border: 1px solid red;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports @import with multiple files in a single directive', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $codePath = $tmpDir . '/_code.scss';
            $listsPath = $tmpDir . '/_lists.scss';

            file_put_contents($codePath, <<<'SCSS'
            code {
              padding: .25em;
              line-height: 0;
            }
            SCSS);

            file_put_contents($listsPath, <<<'SCSS'
            ul, ol {
              text-align: left;

              & & {
                padding: {
                  bottom: 0;
                  left: 0;
                }
              }
            }
            SCSS);

            try {
                $loader = new Loader([$tmpDir]);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @import "code", "lists";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                code {
                  padding: .25em;
                  line-height: 0;
                }
                ul, ol {
                  text-align: left;
                }
                ul ul, ol ol {
                  padding-bottom: 0;
                  padding-left: 0;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($codePath)) {
                    unlink($codePath);
                }

                if (file_exists($listsPath)) {
                    unlink($listsPath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('keeps css-like @import directives without loading files', function () {
            $source = <<<'SCSS'
            @import "theme.css";
            @import "https://fonts.googleapis.com/css?family=Droid+Sans";
            @import url(theme);
            @import "landscape" screen and (orientation: landscape);
            SCSS;

            $expected /** @lang text */
                = <<<'CSS'
                @import "theme.css";
                @import "https://fonts.googleapis.com/css?family=Droid+Sans";
                @import url(theme);
                @import "landscape" screen and (orientation: landscape);
                CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('supports interpolation in css-like @import directives', function () {
            $source = <<<'SCSS'
            @mixin google-font($family) {
              @import url("https://fonts.googleapis.com/css?family=#{$family}");
            }

            @include google-font("Droid Sans");
            SCSS;

            $expected /** @lang text */
                = <<<'CSS'
                @import url("https://fonts.googleapis.com/css?family=Droid Sans");
                CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('prefers import-only files for @import', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $importOnlyPath = $tmpDir . '/_library.import.scss';
            $modulePath = $tmpDir . '/_library.scss';

            file_put_contents($importOnlyPath, <<<'SCSS'
            .from-import-only {
              color: red;
            }
            SCSS);

            file_put_contents($modulePath, <<<'SCSS'
            .from-module {
              color: blue;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @import "library";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-import-only {
                  color: red;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($importOnlyPath)) {
                    unlink($importOnlyPath);
                }

                if (file_exists($modulePath)) {
                    unlink($modulePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('uses prefixed variables to configure forwarded module in import-only file', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $importOnlyPath = $tmpDir . '/_code.import.scss';
            $modulePath = $tmpDir . '/_code.scss';

            file_put_contents($importOnlyPath, <<<'SCSS'
            @forward "code" as lib-*;
            SCSS);

            file_put_contents($modulePath, <<<'SCSS'
            $color: blue !default;

            a {
              color: $color;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                $lib-color: green;
                @import "code";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                a {
                  color: green;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($importOnlyPath)) {
                    unlink($importOnlyPath);
                }

                if (file_exists($modulePath)) {
                    unlink($modulePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('ignores import-only files for @use', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $importOnlyPath = $tmpDir . '/_library.import.scss';
            $modulePath = $tmpDir . '/_library.scss';

            file_put_contents($importOnlyPath, <<<'SCSS'
            .from-import-only {
              color: red;
            }
            SCSS);

            file_put_contents($modulePath, <<<'SCSS'
            .from-module {
              color: blue;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "library";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-module {
                  color: blue;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($importOnlyPath)) {
                    unlink($importOnlyPath);
                }

                if (file_exists($modulePath)) {
                    unlink($modulePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('throws for @use when only import-only file exists', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $importOnlyPath = $tmpDir . '/_library.import.scss';

            file_put_contents($importOnlyPath, <<<'SCSS'
            .from-import-only {
              color: red;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "library";
                SCSS;

                expect(fn() => $compiler->compileString($source))
                    ->toThrow(ModuleResolutionException::class);
            } finally {
                if (file_exists($importOnlyPath)) {
                    unlink($importOnlyPath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('makes variables mixins and functions globally available after @import', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $basePath = $tmpDir . '/_base.scss';
            $themePath = $tmpDir . '/_theme.scss';

            file_put_contents($basePath, <<<'SCSS'
            $import-color: red;

            @mixin imported-border() {
              border: 1px solid $import-color;
            }

            @function imported-size() {
              @return 12px;
            }
            SCSS);

            file_put_contents($themePath, <<<'SCSS'
            $theme-color: blue;

            @function theme-size() {
              @return 14px;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @import "base", "theme";

                .test {
                  color: $import-color;
                  background: $theme-color;
                  @include imported-border();
                  width: imported-size();
                  height: theme-size();
                }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .test {
                  color: red;
                  background: blue;
                  border: 1px solid red;
                  width: 12px;
                  height: 14px;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($basePath)) {
                    unlink($basePath);
                }

                if (file_exists($themePath)) {
                    unlink($themePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('flattens selectors for @import inside nested rule context', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $codePath = $tmpDir . '/_code.scss';

            file_put_contents($codePath, <<<'SCSS'
            pre, code {
              font-family: "Source Code Pro", Helvetica, Arial;
              border-radius: 4px;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                .theme-sample {
                  @import "code";
                }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .theme-sample pre, .theme-sample code {
                  font-family: "Source Code Pro", Helvetica, Arial;
                  border-radius: 4px;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($codePath)) {
                    unlink($codePath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('supports @forward with @use namespace access', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $forwarderPath = $tmpDir . '/_entry.scss';

            file_put_contents($forwarderPath, <<<'SCSS'
            @forward "_forwarded.scss";
            SCSS);

            try {
                $loader = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @use "_entry.scss" as lib;
                .test {
                  color: lib.forwarded-fn();
                  @include lib.forwarded-mixin();
                }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-forwarded {
                  value: forwarded;
                }
                .test {
                  color: green;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($forwarderPath)) {
                    unlink($forwarderPath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('supports @forward with as prefix-*', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $forwarderPath = $tmpDir . '/_entry.scss';

            file_put_contents($forwarderPath, <<<'SCSS'
            @forward "_forwarded.scss" as list-*;
            SCSS);

            try {
                $loader = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @use "_entry.scss" as lib;
                .test {
                  color: lib.list-forwarded-fn();
                  @include lib.list-forwarded-mixin();
                }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-forwarded {
                  value: forwarded;
                }
                .test {
                  color: green;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($forwarderPath)) {
                    unlink($forwarderPath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('supports @forward with configuration and !default override from @use', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $codePath = $tmpDir . '/_code.scss';
            $listsPath = $tmpDir . '/_lists.scss';

            file_put_contents($codePath, <<<'SCSS'
            $black: #000 !default;
            $border-radius: 0.25rem !default;
            $box-shadow: 0 0.5rem 1rem rgba($black, 0.15) !default;

            code {
              border-radius: $border-radius;
              box-shadow: $box-shadow;
            }
            SCSS);

            file_put_contents($listsPath, <<<'SCSS'
            @forward "code" with (
              $black: #222 !default,
              $border-radius: 0.1rem !default
            );
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "lists" with ($black: #333);
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                code {
                  border-radius: .1rem;
                  box-shadow: 0 .5rem 1rem rgba(51, 51, 51, .15);
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($codePath)) {
                    unlink($codePath);
                }

                if (file_exists($listsPath)) {
                    unlink($listsPath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('supports @forward hide for variables', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $forwarderPath = $tmpDir . '/_entry.scss';

            file_put_contents($forwarderPath, <<<'SCSS'
            @forward "_forwarded.scss" hide $forward-color;
            SCSS);

            try {
                $loader = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @use "_entry.scss" as lib;
                .test { color: lib.$forward-color; }
                SCSS;

                expect(fn() => $compiler->compileString($source))
                    ->toThrow(UndefinedSymbolException::class, "Undefined variable \$forward-color in module 'lib'.");
            } finally {
                if (file_exists($forwarderPath)) {
                    unlink($forwarderPath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('supports @forward show for selected members', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $forwarderPath = $tmpDir . '/_entry.scss';

            file_put_contents($forwarderPath, <<<'SCSS'
            @forward "_forwarded.scss" show forwarded-fn, $forward-color;
            SCSS);

            try {
                $loader = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @use "_entry.scss" as lib;
                .test {
                  color: lib.forwarded-fn();
                  border-color: lib.$forward-color;
                }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-forwarded {
                  value: forwarded;
                }
                .test {
                  color: green;
                  border-color: green;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($forwarderPath)) {
                    unlink($forwarderPath);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('evaluates namespaced sass:map functions', function () {
            $source = <<<'SCSS'
            @use "sass:map";
            $m: (a: 1, b: 2);
            .test {
              value: map.get($m, b);
              has: map.has-key($m, c);
              keys: map.keys($m);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: 2;
              has: false;
              keys: a, b;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates global sass:map aliases', function () {
            $source = <<<'SCSS'
            $m: (a: 1, b: 2);
            .test {
              value: map-get($m, a);
              has: map-has-key($m, b);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              value: 1;
              has: true;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates namespaced sass:string functions', function () {
            $source = <<<'SCSS'
            @use "sass:string";
            .test {
              len: string.length(hello);
              inserted: string.insert(abcd, X, 3);
              sliced: string.slice(abcdef, 2, 4);
              sliced-zero: string.slice(abcdef, 0, 2);
              upper: string.to-upper-case(ab);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              len: 5;
              inserted: abXcd;
              sliced: bcd;
              sliced-zero: ab;
              upper: AB;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates global sass:string aliases', function () {
            $source = <<<'SCSS'
            .test {
              len: str-length(hello);
              idx: str-index(hello, ll);
              lower: to-lower-case(AB);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              len: 5;
              idx: 3;
              lower: ab;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates namespaced sass:math functions', function () {
            $source = <<<'SCSS'
            @use "sass:math";
            .test {
              pow: math.pow(2, 3);
              sqrt: math.sqrt(9);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              pow: 8;
              sqrt: 3;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates global sass:math aliases', function () {
            $source = <<<'SCSS'
            .test {
              rounded: round(1.8px);
              unitless: unitless(10);
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              rounded: 2px;
              unitless: true;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('evaluates sass:math module variables', function () {
            $source = <<<'SCSS'
            @use "sass:math";
            .test {
              epsilon-unitless: math.is-unitless(math.$epsilon);
              safe: math.$max-safe-integer;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              epsilon-unitless: true;
              safe: 9007199254740991;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('ignores built-in sass modules in @use', function () {
            $source = <<<'SCSS'
            @use "sass:color";
            .test { color: red; }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .test {
              color: red;
            }
            CSS;

            $css = $this->compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('imports variables mixins and functions into current scope for wildcard namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_configurable.scss" as *;

            .box {
              @include theme();
              tone: tone();
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .box {
              color: red;
              margin: 8px;
              tone: red;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('imports module variables into current scope for wildcard namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_theme.scss" as *;

            .box {
              color: $primary-color;
              font-size: $font-size;
            }
            SCSS;

            $expected = /** @lang text */ <<<'CSS'
            .box {
              color: #007bff;
              font-size: 16px;
            }
            CSS;

            $css = $compiler->compileString($source);

            expect($css)->toEqualCss($expected);
        });

        it('throws when accessing private module variables via namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_private-module.scss" as priv;

            .box {
              value: priv.$-hidden;
            }
            SCSS;

            expect(fn() => $compiler->compileString($source))
                ->toThrow(UndefinedSymbolException::class, "Undefined variable \$-hidden in module 'priv'.");
        });

        it('does not import private variables into scope for wildcard namespace', function () {
            $compiler = new Compiler(loader: new Loader([__DIR__ . '/../../fixtures']));

            $source = <<<'SCSS'
            @use "_private-module.scss" as *;

            .box {
              visible: $public;
              private: $-hidden;
            }
            SCSS;

            expect(fn() => $compiler->compileString($source))
                ->toThrow(UndefinedSymbolException::class, 'Undefined variable: $-hidden');
        });

        it('compiles mixin from .sass module via @use', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $modulePath = $tmpDir . '/_mixins.sass';

            file_put_contents($modulePath, <<<'SASS'
            =highlight($color)
              border: 1px solid $color
            SASS);

            try {
                $loader = new Loader([$tmpDir]);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @use "mixins";
                .test { @include mixins.highlight(blue); }
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .test {
                  border: 1px solid blue;
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

        it('resolves directory _index.scss for @use', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-index-use-' . uniqid('', true);
            $moduleDir = $tmpDir . '/foundation';
            mkdir($moduleDir, 0777, true);

            $indexPath = $moduleDir . '/_index.scss';
            file_put_contents($indexPath, <<<'SCSS'
            .from-index {
              color: red;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "foundation";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-index {
                  color: red;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($indexPath)) {
                    unlink($indexPath);
                }

                if (is_dir($moduleDir)) {
                    rmdir($moduleDir);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('resolves relative @use from directory _index.scss', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-index-relative-' . uniqid('', true);
            $moduleDir = $tmpDir . '/foundation';
            mkdir($moduleDir, 0777, true);

            $indexPath = $moduleDir . '/_index.scss';
            $codePath = $moduleDir . '/_code.scss';

            file_put_contents($indexPath, <<<'SCSS'
            @use "code";
            SCSS);

            file_put_contents($codePath, <<<'SCSS'
            .from-code {
              color: red;
            }
            SCSS);

            try {
                $compiler = new Compiler(loader: new Loader([$tmpDir]));

                $source = <<<'SCSS'
                @use "foundation";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                .from-code {
                  color: red;
                }
                CSS;

                $css = $compiler->compileString($source);

                expect($css)->toEqualCss($expected);
            } finally {
                if (file_exists($indexPath)) {
                    unlink($indexPath);
                }

                if (file_exists($codePath)) {
                    unlink($codePath);
                }

                if (is_dir($moduleDir)) {
                    rmdir($moduleDir);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });
    });
});
