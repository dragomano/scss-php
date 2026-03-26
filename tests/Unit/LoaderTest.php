<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Loader;

describe('Loader', function () {
    beforeEach(function () {
        $this->loader = new Loader([__DIR__ . '/../fixtures']);
    });

    describe('load()', function () {
        it('returns virtual content for sass: modules', function () {
            $result = $this->loader->load('sass:color');

            expect($result)->toBe([
                'path'    => 'sass:color',
                'content' => '',
            ]);
        });

        it('loads SCSS file', function () {
            $result = $this->loader->load('_theme.scss');

            expect($result)->toBeArray()
                ->and($result)->toHaveKey('path')
                ->and($result)->toHaveKey('content')
                ->and($result['path'])->toEndWith('_theme.scss')
                ->and($result['content'])->toContain('$primary-color');
        });

        it('loads CSS file by module name without extension', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-loader-css-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $path = $tmpDir . '/_base.css';
            file_put_contents($path, 'body { color: red; }');

            try {
                $loader = new Loader([$tmpDir]);
                $result = $loader->load('base');

                expect($result)->toBeArray()
                    ->and($result['path'])->toEndWith('_base.css')
                    ->and($result['content'])->toContain('color: red');
            } finally {
                if (file_exists($path)) {
                    unlink($path);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('loads directory index file by module name', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-loader-index-' . uniqid('', true);
            $moduleDir = $tmpDir . '/foundation';
            mkdir($moduleDir, 0777, true);

            $path = $moduleDir . '/_index.scss';
            file_put_contents($path, '$radius: 4px;');

            try {
                $loader = new Loader([$tmpDir]);
                $result = $loader->load('foundation');

                expect($result)->toBeArray()
                    ->and($result['path'])->toEndWith('_index.scss')
                    ->and($result['content'])->toContain('$radius');
            } finally {
                if (file_exists($path)) {
                    unlink($path);
                }

                if (is_dir($moduleDir)) {
                    rmdir($moduleDir);
                }

                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('throws exception for missing file', function () {
            expect(fn() => $this->loader->load('missing.scss'))->toThrow(ModuleResolutionException::class);
        });

        it('rejects path traversal outside configured include paths', function () {
            $tmpDir = sys_get_temp_dir() . '/dart-sass-loader-traversal-' . uniqid('', true);
            mkdir($tmpDir, 0777, true);

            $victimDir  = sys_get_temp_dir() . '/dart-sass-loader-victim-' . uniqid('', true);
            mkdir($victimDir, 0777, true);

            $victimFile = $victimDir . '/secret.scss';
            file_put_contents($victimFile, '$secret: 42;');

            try {
                $loader = new Loader([$tmpDir]);

                // Attempt to reach outside the configured include directory
                expect(fn() => $loader->load('../' . basename($victimDir) . '/secret.scss'))
                    ->toThrow(ModuleResolutionException::class);
            } finally {
                if (file_exists($victimFile)) {
                    unlink($victimFile);
                }
                if (is_dir($victimDir)) {
                    rmdir($victimDir);
                }
                if (is_dir($tmpDir)) {
                    rmdir($tmpDir);
                }
            }
        });

        it('resolves current directory before configured include paths', function () {
            $workDir = sys_get_temp_dir() . '/dart-sass-loader-cwd-' . uniqid('', true);
            $loadDir = sys_get_temp_dir() . '/dart-sass-loader-loadpath-' . uniqid('', true);

            mkdir($workDir, 0777, true);
            mkdir($loadDir, 0777, true);

            $cwdPath = $workDir . '/style.scss';
            $loadPath = $loadDir . '/style.scss';

            file_put_contents($cwdPath, '.from-cwd { color: red; }');
            file_put_contents($loadPath, '.from-loadpath { color: blue; }');

            $initialCwd = getcwd();

            try {
                chdir($workDir);

                $loader = new Loader([$loadDir]);
                $result = $loader->load('style');

                expect($result['content'])->toContain('.from-cwd')
                    ->and($result['content'])->not->toContain('.from-loadpath');
            } finally {
                if ($initialCwd !== false) {
                    chdir($initialCwd);
                }

                if (file_exists($cwdPath)) {
                    unlink($cwdPath);
                }

                if (file_exists($loadPath)) {
                    unlink($loadPath);
                }

                if (is_dir($workDir)) {
                    rmdir($workDir);
                }

                if (is_dir($loadDir)) {
                    rmdir($loadDir);
                }
            }
        });
    });

    describe('addPath()', function () {
        it('adds new path to search paths', function () {
            $newPath = __DIR__ . '/../fixtures';
            $this->loader->addPath($newPath);

            $result = $this->loader->load('_test.scss');
            expect($result['content'])->toContain('.test');
        });

        it('prioritizes the most recently added path', function () {
            $tmpDirA = sys_get_temp_dir() . '/dart-sass-loader-a-' . uniqid('', true);
            $tmpDirB = sys_get_temp_dir() . '/dart-sass-loader-b-' . uniqid('', true);

            mkdir($tmpDirA, 0777, true);
            mkdir($tmpDirB, 0777, true);

            $fileName = '_priority.scss';
            $pathA = $tmpDirA . '/' . $fileName;
            $pathB = $tmpDirB . '/' . $fileName;

            file_put_contents($pathA, '.from-a { color: red; }');
            file_put_contents($pathB, '.from-b { color: blue; }');

            try {
                $loader = new Loader([$tmpDirA]);
                $loader->addPath($tmpDirB);

                $result = $loader->load($fileName);

                expect($result['content'])->toContain('.from-b');
            } finally {
                if (file_exists($pathA)) {
                    unlink($pathA);
                }

                if (file_exists($pathB)) {
                    unlink($pathB);
                }

                if (is_dir($tmpDirA)) {
                    rmdir($tmpDirA);
                }

                if (is_dir($tmpDirB)) {
                    rmdir($tmpDirB);
                }
            }
        });

        it('moves an existing path to front when added again', function () {
            $tmpDirA = sys_get_temp_dir() . '/dart-sass-loader-readd-a-' . uniqid('', true);
            $tmpDirB = sys_get_temp_dir() . '/dart-sass-loader-readd-b-' . uniqid('', true);

            mkdir($tmpDirA, 0777, true);
            mkdir($tmpDirB, 0777, true);

            $fileName = '_priority.scss';
            $pathA = $tmpDirA . '/' . $fileName;
            $pathB = $tmpDirB . '/' . $fileName;

            file_put_contents($pathA, '.from-a { color: red; }');
            file_put_contents($pathB, '.from-b { color: blue; }');

            try {
                $loader = new Loader([$tmpDirA, $tmpDirB]);

                $before = $loader->load($fileName);
                expect($before['content'])->toContain('.from-a');

                $loader->addPath($tmpDirB);
                $after = $loader->load($fileName);

                expect($after['content'])->toContain('.from-b');
            } finally {
                if (file_exists($pathA)) {
                    unlink($pathA);
                }

                if (file_exists($pathB)) {
                    unlink($pathB);
                }

                if (is_dir($tmpDirA)) {
                    rmdir($tmpDirA);
                }

                if (is_dir($tmpDirB)) {
                    rmdir($tmpDirB);
                }
            }
        });
    });
});
