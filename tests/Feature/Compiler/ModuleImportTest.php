<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Loader;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

        it('supports @import for css output and exported members', function () {
            $loader   = new Loader([__DIR__ . '/../../fixtures']);
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
                $loader   = new Loader([$tmpDir]);
                $compiler = new Compiler(loader: $loader);

                $source = <<<'SCSS'
                @import "code", "lists";
                SCSS;

                $expected = /** @lang text */ <<<'CSS'
                code {
                  padding: 0.25em;
                  line-height: 0;
                }

                ul, ol {
                  text-align: left;
                }
                ul ul, ul ol, ol ul, ol ol {
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

            $basePath  = $tmpDir . '/_base.scss';
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
    });
});
