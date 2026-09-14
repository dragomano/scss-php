<?php

declare(strict_types=1);

use Bugo\SCSS\Compiler;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Loader;
use Tests\Support\ArrayLogger;

describe('Compiler', function () {
    beforeEach(function () {
        $this->logger   = new ArrayLogger();
        $this->compiler = new Compiler(logger: $this->logger);
    });

    describe('compileString()', function () {

        it('supports @forward with @use namespace access', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-test-' . uniqid('', true);

            mkdir($tmpDir, 0777, true);

            $forwarderPath = $tmpDir . '/_entry.scss';

            file_put_contents($forwarderPath, <<<'SCSS'
            @forward "_forwarded.scss";
            SCSS);

            try {
                $loader   = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
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
                $loader   = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
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

            $codePath  = $tmpDir . '/_code.scss';
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
                  border-radius: 0.1rem;
                  box-shadow: 0 0.5rem 1rem rgba(51, 51, 51, 0.15);
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
                $loader   = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
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
                $loader   = new Loader([$tmpDir, __DIR__ . '/../../fixtures']);
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
    });
});
