<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final readonly class DeferredChunk implements OutputChunk
{
    /**
     * @param array<int, SourceMapMapping> $mappings
     */
    public function __construct(
        private string $content,
        public int $baseLine,
        public int $baseColumn,
        public array $mappings,
    ) {}

    public function content(): string
    {
        return $this->content;
    }
}
