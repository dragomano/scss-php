<?php

declare(strict_types=1);

namespace Bugo\SCSS;

interface LoaderInterface
{
    public function addPath(string $path): void;

    public function load(string $url, bool $fromImport = false): LoadedFile;

    /**
     * Replaces the directory that import resolution checks first (the importing
     * module's directory) and returns the previously active one, or null.
     */
    public function setImportDirectory(?string $directory): ?string;
}
