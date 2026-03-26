<?php

declare(strict_types=1);

namespace Bugo\SCSS;

interface LoaderInterface
{
    public function addPath(string $path): void;

    /**
     * @return array{path: string, content: string}
     */
    public function load(string $url, bool $fromImport = false): array;
}
