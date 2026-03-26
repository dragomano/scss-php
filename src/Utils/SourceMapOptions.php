<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final readonly class SourceMapOptions
{
    /**
     * @param list<string> $sources
     */
    public function __construct(
        public int $outputLines,
        public string $sourceMapRoot = '',
        public string $sourceContent = '',
        public bool $includeSources = false,
        public array $sources = []
    ) {}
}
