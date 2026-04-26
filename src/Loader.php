<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Closure;
use Symfony\Component\Filesystem\Path;

use function array_unshift;
use function file_get_contents;
use function getcwd;
use function in_array;
use function is_file;
use function is_readable;
use function is_string;
use function pathinfo;
use function realpath;
use function rtrim;
use function str_ends_with;
use function strtolower;

use const DIRECTORY_SEPARATOR;

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

    /**
     * @return array{path: string, content: string}
     */
    public function load(string $url, bool $fromImport = false): array
    {
        if (str_starts_with($url, 'sass:')) {
            return ['path' => $url, 'content' => ''];
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

    /**
     * @return array{path: string, content: string}|null
     */
    private function tryLoadFile(string $path, bool $fromImport): ?array
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

            return ['path' => $resolvedPath, 'content' => $content];
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
        $url     = rtrim($url, '/\\');
        $parts   = pathinfo($url);
        $dirname = $parts['dirname'] ?? '';
        $dir     = $dirname === '.' || $dirname === '' ? '' : $dirname . DIRECTORY_SEPARATOR;
        $name    = $parts['filename'];

        $extensions = isset($parts['extension']) ? ['.' . $parts['extension']] : ['.scss', '.sass', '.css'];
        $candidates = [];

        foreach ($extensions as $ext) {
            if ($fromImport && ($ext === '.scss' || $ext === '.sass')) {
                $candidates[] = $dir . '_' . $name . '.import' . $ext;
                $candidates[] = $dir . $name . '.import' . $ext;
                $candidates[] = $dir . $name . DIRECTORY_SEPARATOR . '_index.import' . $ext;
                $candidates[] = $dir . $name . DIRECTORY_SEPARATOR . 'index.import' . $ext;
            }

            $candidates[] = $dir . '_' . $name . $ext;
            $candidates[] = $dir . $name . $ext;
            $candidates[] = $dir . $name . DIRECTORY_SEPARATOR . '_index' . $ext;
            $candidates[] = $dir . $name . DIRECTORY_SEPARATOR . 'index' . $ext;
        }

        return $candidates;
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
