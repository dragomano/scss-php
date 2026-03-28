<?php

declare(strict_types=1);

namespace Bugo\SCSS\Cache;

use Bugo\SCSS\CompilerInterface;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Syntax;
use Psr\SimpleCache\CacheInterface;
use Psr\SimpleCache\InvalidArgumentException;

use function file_exists;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function hash;
use function implode;
use function is_array;
use function is_int;
use function is_string;

final readonly class CachingCompiler implements CompilerInterface
{
    public function __construct(
        private CompilerInterface $inner,
        private CacheInterface $cache,
        private TrackingLoader $trackingLoader,
        private CompilerOptions $options = new CompilerOptions(),
        private int $ttl = 0
    ) {}

    public function compileString(string $source, ?Syntax $syntax = null, string $sourceFile = ''): string
    {
        return $this->inner->compileString($source, $syntax, $sourceFile);
    }

    /**
     * @throws InvalidArgumentException
     */
    public function compileFile(string $path): string
    {
        $resolvedPath = $this->trackingLoader->load($path)['path'];

        $key    = $this->buildCacheKey($resolvedPath);
        /** @psalm-suppress MixedAssignment */
        $cached = $this->cache->get($key);

        if ($this->isValidCacheEntry($cached) && $this->isEntryFresh($cached)) {
            $this->restoreSourceMap($cached);

            return $cached['css'];
        }

        $this->trackingLoader->reset();

        $css = $this->inner->compileFile($path);

        $entry = [
            'css'             => $css,
            'source_map'      => $this->readSourceMap(),
            'source_map_file' => $this->options->sourceMapFile,
            'deps'            => $this->trackingLoader->getLoadedFiles(),
        ];

        $this->cache->set($key, $entry, $this->ttl > 0 ? $this->ttl : null);

        return $css;
    }

    private function buildCacheKey(string $path): string
    {
        $parts = [
            $path,
            $this->options->style->name,
            $this->options->outputFile,
            $this->options->sourceMapFile ?? '',
            $this->options->includeSources ? '1' : '0',
            $this->options->outputHexColors ? '1' : '0',
            $this->options->splitRules ? '1' : '0',
        ];

        return 'scss_' . hash('xxh32', implode('|', $parts));
    }

    private function readSourceMap(): ?string
    {
        if ($this->options->sourceMapFile === null || ! file_exists($this->options->sourceMapFile)) {
            return null;
        }

        $sourceMap = file_get_contents($this->options->sourceMapFile);

        return is_string($sourceMap) ? $sourceMap : null;
    }

    /**
     * @param mixed $cached
     * @return bool
     * @phpstan-assert-if-true array{
     *     css: string,
     *     source_map: string|null,
     *     source_map_file: string|null,
     *     deps: array<string, int>
     * } $cached
     */
    private function isValidCacheEntry(mixed $cached): bool
    {
        if (! is_array($cached)) {
            return false;
        }

        if (! is_string($cached['css'] ?? null)) {
            return false;
        }

        $sourceMap = $cached['source_map'] ?? null;

        if ($sourceMap !== null && ! is_string($sourceMap)) {
            return false;
        }

        $sourceMapFile = $cached['source_map_file'] ?? null;

        if ($sourceMapFile !== null && ! is_string($sourceMapFile)) {
            return false;
        }

        if (! is_array($cached['deps'] ?? null)) {
            return false;
        }

        foreach ($cached['deps'] as $filePath => $savedMtime) {
            if (! is_string($filePath) || ! is_int($savedMtime)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *     css: string,
     *     source_map: string|null,
     *     source_map_file: string|null,
     *     deps: array<string, int>
     * } $entry
     */
    private function isEntryFresh(array $entry): bool
    {
        foreach ($entry['deps'] as $filePath => $savedMtime) {
            $currentMtime = filemtime($filePath);

            if (! is_int($currentMtime) || $currentMtime !== $savedMtime) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array{
     *     css: string,
     *     source_map: string|null,
     *     source_map_file: string|null,
     *     deps: array<string, int>
     * } $entry
     */
    private function restoreSourceMap(array $entry): void
    {
        if ($this->options->sourceMapFile === null || $entry['source_map'] === null) {
            return;
        }

        file_put_contents($this->options->sourceMapFile, $entry['source_map']);
    }
}
