<?php

declare(strict_types=1);

namespace Bugo\SCSS;

final readonly class LoadedFile
{
    public function __construct(
        public string $path,
        public string $content,
    ) {}
}
