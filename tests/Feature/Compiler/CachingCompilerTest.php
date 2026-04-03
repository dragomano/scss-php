<?php

declare(strict_types=1);

use Bugo\SCSS\Cache\CachingCompiler;
use Bugo\SCSS\Cache\TrackingLoader;
use Bugo\SCSS\Compiler;
use Bugo\SCSS\CompilerInterface;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Loader;
use Bugo\SCSS\Syntax;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Psr16Cache;

describe('CachingCompiler', function () {
    beforeEach(function () {
        $this->tmpDir = sys_get_temp_dir() . '/dart-sass-cache-' . uniqid('', true);

        $this->makeCountingCompiler = static fn(CompilerInterface $inner) => new class ($inner) implements CompilerInterface {
            public int $compileFileCalls = 0;

            public int $compileStringCalls = 0;

            public function __construct(private readonly CompilerInterface $inner) {}

            public function compileString(string $source, ?Syntax $syntax = null, string $sourceFile = ''): string
            {
                $this->compileStringCalls++;

                return $this->inner->compileString($source, $syntax, $sourceFile);
            }

            public function compileFile(string $path): string
            {
                $this->compileFileCalls++;

                return $this->inner->compileFile($path);
            }
        };

        $this->makeMutableCache = static fn() => new class implements CacheInterface {
            /** @var array<string, mixed> */
            private array $items = [];

            public function get(string $key, mixed $default = null): mixed
            {
                return $this->items[$key] ?? $default;
            }

            public function set(string $key, mixed $value, int|\DateInterval|null $ttl = null): bool
            {
                $this->items[$key] = $value;

                return true;
            }

            public function delete(string $key): bool
            {
                unset($this->items[$key]);

                return true;
            }

            public function clear(): bool
            {
                $this->items = [];

                return true;
            }

            public function getMultiple(iterable $keys, mixed $default = null): iterable
            {
                $values = [];

                foreach ($keys as $key) {
                    $values[$key] = $this->get($key, $default);
                }

                return $values;
            }

            public function setMultiple(iterable $values, int|\DateInterval|null $ttl = null): bool
            {
                foreach ($values as $key => $value) {
                    $this->set((string) $key, $value, $ttl);
                }

                return true;
            }

            public function deleteMultiple(iterable $keys): bool
            {
                foreach ($keys as $key) {
                    $this->delete((string) $key);
                }

                return true;
            }

            public function has(string $key): bool
            {
                return array_key_exists($key, $this->items);
            }
        };

        mkdir($this->tmpDir, 0777, true);
    });

    afterEach(function () {
        foreach (['main.scss', '_variables.scss', 'output.css.map'] as $file) {
            $path = $this->tmpDir . '/' . $file;

            if (file_exists($path)) {
                unlink($path);
            }
        }

        if (is_dir($this->tmpDir)) {
            rmdir($this->tmpDir);
        }
    });

    it('reuses cached css until a tracked dependency changes', function () {
        $entryPath      = $this->tmpDir . '/main.scss';
        $dependencyPath = $this->tmpDir . '/_variables.scss';

        file_put_contents($dependencyPath, '$color: red;');
        file_put_contents($entryPath, <<<'SCSS'
        @use 'variables';

        .box {
          color: variables.$color;
        }
        SCSS);

        $trackingLoader = new TrackingLoader(new Loader([$this->tmpDir]));
        $compiler       = ($this->makeCountingCompiler)(new Compiler(loader: $trackingLoader));
        $cachedCompiler = new CachingCompiler(
            $compiler,
            new Psr16Cache(new ArrayAdapter()),
            $trackingLoader,
        );

        $firstCss  = $cachedCompiler->compileFile($entryPath);
        $secondCss = $cachedCompiler->compileFile($entryPath);

        file_put_contents($dependencyPath, '$color: blue;');
        touch($dependencyPath, time() + 2);

        $thirdCss = $cachedCompiler->compileFile($entryPath);

        $expectedFirst = /** @lang text */ <<<'CSS'
        .box {
          color: red;
        }
        CSS;

        $expectedThird = /** @lang text */ <<<'CSS'
        .box {
          color: blue;
        }
        CSS;

        expect($firstCss)->toEqualCss($expectedFirst)
            ->and($secondCss)->toEqualCss($expectedFirst)
            ->and($thirdCss)->toEqualCss($expectedThird)
            ->and($compiler->compileFileCalls)->toBe(2);
    });

    it('restores the cached source map file on cache hits', function () {
        $entryPath      = $this->tmpDir . '/main.scss';
        $dependencyPath = $this->tmpDir . '/_variables.scss';
        $mapPath        = $this->tmpDir . '/output.css.map';

        file_put_contents($dependencyPath, '$color: red;');
        file_put_contents($entryPath, <<<'SCSS'
        @use 'variables';

        .box {
          color: variables.$color;
        }
        SCSS);

        $options        = new CompilerOptions(sourceMapFile: $mapPath);
        $trackingLoader = new TrackingLoader(new Loader([$this->tmpDir]));
        $compiler       = ($this->makeCountingCompiler)(new Compiler($options, $trackingLoader));
        $cachedCompiler = new CachingCompiler(
            $compiler,
            new Psr16Cache(new ArrayAdapter()),
            $trackingLoader,
            $options,
        );

        $firstCss = $cachedCompiler->compileFile($entryPath);
        $firstMap = (string) file_get_contents($mapPath);

        unlink($mapPath);

        $secondCss = $cachedCompiler->compileFile($entryPath);
        $secondMap = (string) file_get_contents($mapPath);

        expect($firstCss)->toBe($secondCss)
            ->and($firstMap)->toBe($secondMap)
            ->and($secondCss)->toContain('sourceMappingURL=')
            ->and($compiler->compileFileCalls)->toBe(1);
    });

    it('delegates compileString without caching', function () {
        $trackingLoader = new TrackingLoader(new Loader([$this->tmpDir]));
        $compiler       = ($this->makeCountingCompiler)(new Compiler(loader: $trackingLoader));
        $cachedCompiler = new CachingCompiler(
            $compiler,
            new Psr16Cache(new ArrayAdapter()),
            $trackingLoader,
        );

        $scss = '.box { color: red; }';

        $expected = /** @lang text */ <<<'CSS'
        .box {
          color: red;
        }
        CSS;

        expect($cachedCompiler->compileString($scss))->toEqualCss($expected)
            ->and($compiler->compileStringCalls)->toBe(1)
            ->and($compiler->compileFileCalls)->toBe(0);
    });

    it('ignores invalid cached entries', function (mixed $entry) {
        $entryPath      = $this->tmpDir . '/main.scss';
        $dependencyPath = $this->tmpDir . '/_variables.scss';

        file_put_contents($dependencyPath, '$color: red;');
        file_put_contents($entryPath, <<<'SCSS'
        @use 'variables';

        .box {
          color: variables.$color;
        }
        SCSS);

        $cache          = ($this->makeMutableCache)();
        $trackingLoader = new TrackingLoader(new Loader([$this->tmpDir]));
        $compiler       = ($this->makeCountingCompiler)(new Compiler(loader: $trackingLoader));
        $cachedCompiler = new CachingCompiler($compiler, $cache, $trackingLoader);

        $cacheKey = buildCacheKey($entryPath);

        $expected = /** @lang text */ <<<'CSS'
        .box {
          color: red;
        }
        CSS;

        expect($cache->set($cacheKey, $entry))->toBeTrue()
            ->and($cache->has($cacheKey))->toBeTrue()
            ->and($cachedCompiler->compileFile($entryPath))->toEqualCss($expected)
            ->and($compiler->compileFileCalls)->toBe(1);
    })->with([
        'css is not string' => [[
            'css'             => 123,
            'source_map'      => null,
            'source_map_file' => null,
            'deps'            => [],
        ]],
        'source_map is not string or null' => [[
            'css'             => '.box{color:red}',
            'source_map'      => 123,
            'source_map_file' => null,
            'deps'            => [],
        ]],
        'source_map_file is not string or null' => [[
            'css'             => '.box{color:red}',
            'source_map'      => null,
            'source_map_file' => 123,
            'deps'            => [],
        ]],
        'deps is not array' => [[
            'css'             => '.box{color:red}',
            'source_map'      => null,
            'source_map_file' => null,
            'deps'            => 'broken',
        ]],
        'deps contains invalid item' => [[
            'css'             => '.box{color:red}',
            'source_map'      => null,
            'source_map_file' => null,
            'deps'            => ['broken.scss' => 'mtime'],
        ]],
    ]);
});

function buildCacheKey(string $path, ?CompilerOptions $options = null): string
{
    $options ??= new CompilerOptions();
    $resolvedPath = realpath($path);

    if (! is_string($resolvedPath)) {
        throw new LogicException('Failed to resolve cache key path.');
    }

    $parts = [
        $resolvedPath,
        $options->style->name,
        $options->outputFile,
        $options->sourceMapFile ?? '',
        $options->includeSources ? '1' : '0',
        $options->outputHexColors ? '1' : '0',
        $options->splitRules ? '1' : '0',
    ];

    return 'scss_' . hash('xxh32', implode('|', $parts));
}
