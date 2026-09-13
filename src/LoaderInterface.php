<?php

declare(strict_types=1);

namespace Bugo\SCSS;

interface LoaderInterface
{
    public function addPath(string $path): void;

    public function load(string $url, bool $fromImport = false): LoadedFile;
}
