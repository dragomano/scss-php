<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Closure;
use Symfony\Component\Filesystem\Path;

use function array_merge;
use function array_unshift;
use function basename;
use function file_get_contents;
use function getcwd;
use function in_array;
use function is_file;
use function is_readable;
use function is_string;
use function realpath;
use function rtrim;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function strrpos;
use function strtolower;
use function substr;

final class Loader implements LoaderInterface
{
    /** @var array<int, string> */
    private array $includePaths = [];

    /** @var Closure(): (string|false) */
    private readonly Closure $workDir;

    /** @var Closure(string): (string|false) */
    private readonly Closure $fileReader;

    /**
     * @param array<int, string> $includePaths
     * @param Closure(): (string|false)|null $workDir
     * @param Closure(string): (string|false)|null $fileReader
     */
    public function __construct(
        array $includePaths = [],
        ?Closure $workDir = null,
        ?Closure $fileReader = null,
    ) {
        $this->workDir    = $workDir ?? getcwd(...);
        $this->fileReader = $fileReader ?? file_get_contents(...);

        foreach ($includePaths as $path) {
            $real = realpath($path);

            if ($real !== false) {
                if (! in_array($real, $this->includePaths, true)) {
                    $this->includePaths[] = $real;
                }
            }
        }
    }

    public function addPath(string $path): void
    {
        $real = realpath($path);

        if ($real !== false) {
            $paths = [];

            foreach ($this->includePaths as $includePath) {
                if ($includePath !== $real) {
                    $paths[] = $includePath;
                }
            }

            $this->includePaths = $paths;

            array_unshift($this->includePaths, $real);
        }
    }

    public function load(string $url, bool $fromImport = false): LoadedFile
    {
        if (str_starts_with($url, 'sass:')) {
            return new LoadedFile($url, '');
        }

        if ($loaded = $this->tryLoadFile($url, $fromImport)) {
            return $loaded;
        }

        foreach ($this->resolveSearchPaths() as $dir) {
            foreach ($this->resolveCandidates($url, $fromImport) as $candidate) {
                $fullPath = Path::join($dir, $candidate);

                if ($loaded = $this->tryLoadFile($fullPath, $fromImport)) {
                    return $loaded;
                }
            }
        }

        throw ModuleResolutionException::importNotFound($url);
    }

    private function tryLoadFile(string $path, bool $fromImport): ?LoadedFile
    {
        if (! $fromImport && $this->isImportOnlyPath($path)) {
            return null;
        }

        if (is_file($path) && is_readable($path)) {
            $resolvedPath = realpath($path);

            if ($resolvedPath === false || ! $this->isWithinAllowedPaths($resolvedPath)) {
                return null;
            }

            $content = ($this->fileReader)($resolvedPath);

            if (! is_string($content)) {
                return null;
            }

            return new LoadedFile($resolvedPath, $content);
        }

        return null;
    }

    private function isWithinAllowedPaths(string $resolvedPath): bool
    {
        foreach ($this->resolveSearchPaths() as $root) {
            if (Path::isBasePath($root, $resolvedPath)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function resolveCandidates(string $url, bool $fromImport): array
    {
        $url       = rtrim($url, '/\\');
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

    /**
     * @return array<int, string>
     */
    private function resolveSearchPaths(): array
    {
        $cwd = ($this->workDir)();

        if (! is_string($cwd)) {
            return $this->includePaths;
        }

        $resolvedCwd = realpath($cwd);

        if ($resolvedCwd === false || in_array($resolvedCwd, $this->includePaths, true)) {
            return $this->includePaths;
        }

        return [$resolvedCwd, ...$this->includePaths];
    }

    private function isImportOnlyPath(string $path): bool
    {
        $normalized = strtolower($path);

        return str_ends_with($normalized, '.import.scss') || str_ends_with($normalized, '.import.sass');
    }
}
