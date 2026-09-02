<?php

declare(strict_types=1);

namespace Tests\Support;

use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\LoaderInterface;
use Symfony\Component\Filesystem\Path;

/**
 * Loads files from an in-memory map instead of the real filesystem.
 * Resolves URLs with the same candidate rules as the filesystem Loader.
 */
final class MemoryLoader implements LoaderInterface
{
    /** @var array<string, string> */
    private array $files;

    /** @var array<int, string> */
    private array $searchPaths;

    /**
     * @param array<string, string> $files map of virtual path => content
     * @param string $baseDir virtual working directory of the entry file
     */
    public function __construct(array $files, string $baseDir = '')
    {
        foreach ($files as $path => $content) {
            $this->files[$this->normalizePath($path)] = $content;
        }

        $base = $this->normalizePath($baseDir);

        $this->searchPaths = $base === '/' ? ['/'] : [$base, '/'];
    }

    public function addPath(string $path): void
    {
        $path = $this->normalizePath($path);

        if (! in_array($path, $this->searchPaths, true)) {
            array_unshift($this->searchPaths, $path);
        }
    }

    /**
     * @return array{path: string, content: string}
     */
    public function load(string $url, bool $fromImport = false): array
    {
        if (str_starts_with($url, 'sass:')) {
            return ['path' => $url, 'content' => ''];
        }

        $url = rtrim($url, '/\\');

        foreach ($this->searchPaths as $dir) {
            foreach ($this->candidates($url, $fromImport) as $candidate) {
                $path = Path::join($dir, $candidate);

                if (isset($this->files[$path])) {
                    return ['path' => $path, 'content' => $this->files[$path]];
                }
            }
        }

        throw ModuleResolutionException::importNotFound($url);
    }

    private function normalizePath(string $path): string
    {
        $path = str_replace('\\', '/', $path);
        $path = Path::canonicalize($path);

        if ($path === '') {
            return '/';
        }

        if ($path[0] !== '/') {
            $path = '/' . $path;
        }

        return $path;
    }

    /**
     * @return array<int, string>
     */
    private function candidates(string $url, bool $fromImport): array
    {
        $url = rtrim($url, '/\\');

        $lastSlash = strrpos($url, '/');
        $dir       = $lastSlash !== false ? substr($url, 0, $lastSlash) . '/' : '';
        $base      = $lastSlash !== false ? substr($url, $lastSlash + 1) : $url;

        foreach (['.scss', '.sass', '.css'] as $extension) {
            if (str_ends_with($base, $extension)) {
                $prefix = substr($base, 0, -strlen($extension));

                $candidates = [];

                if ($fromImport) {
                    $candidates = array_merge($candidates, $this->suffixedCandidates($dir, $prefix, '.import' . $extension));
                }

                return array_merge($candidates, $this->suffixedCandidates($dir, $prefix, $extension));
            }
        }

        $fileBase = $dir . $base;

        return array_merge(
            $this->extensionCandidates($fileBase, $fromImport),
            $this->extensionCandidates($fileBase . '/index', $fromImport),
        );
    }

    /**
     * @return array<int, string>
     */
    private function extensionCandidates(string $fileBase, bool $fromImport): array
    {
        $lastSlash = strrpos($fileBase, '/');
        $dir       = $lastSlash !== false ? substr($fileBase, 0, $lastSlash) . '/' : '';
        $name      = $lastSlash !== false ? substr($fileBase, $lastSlash + 1) : $fileBase;

        $candidates = [];

        if ($fromImport) {
            foreach (['.import.sass', '.import.scss', '.import.css'] as $suffix) {
                $candidates = array_merge($candidates, $this->suffixedCandidates($dir, $name, $suffix));
            }
        }

        foreach (['.sass', '.scss', '.css'] as $suffix) {
            $candidates = array_merge($candidates, $this->suffixedCandidates($dir, $name, $suffix));
        }

        return $candidates;
    }

    /**
     * @return array<int, string>
     */
    private function suffixedCandidates(string $dir, string $name, string $suffix): array
    {
        $full = $dir . $name . $suffix;
        $base = basename($full);

        if (str_starts_with($base, '_')) {
            return [$full];
        }

        return [$full, $dir . '_' . $name . $suffix];
    }
}
