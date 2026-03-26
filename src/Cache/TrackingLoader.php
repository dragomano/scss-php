<?php

declare(strict_types=1);

namespace Bugo\SCSS\Cache;

use Bugo\SCSS\LoaderInterface;

use function filemtime;
use function is_int;

final class TrackingLoader implements LoaderInterface
{
    /** @var array<string, int> */
    private array $loadedFiles = [];

    public function __construct(private readonly LoaderInterface $inner) {}

    public function addPath(string $path): void
    {
        $this->inner->addPath($path);
    }

    /**
     * @return array{path: string, content: string}
     */
    public function load(string $url, bool $fromImport = false): array
    {
        $result = $this->inner->load($url, $fromImport);
        $mtime  = filemtime($result['path']);

        if (is_int($mtime)) {
            $this->loadedFiles[$result['path']] = $mtime;
        }

        return $result;
    }

    /**
     * @return array<string, int>
     */
    public function getLoadedFiles(): array
    {
        return $this->loadedFiles;
    }

    public function reset(): void
    {
        $this->loadedFiles = [];
    }
}
