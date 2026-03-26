<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final readonly class SourceMapMapping
{
    public function __construct(
        public SourceMapPosition $generated,
        public SourceMapPosition $original,
        public int $sourceIndex = 0
    ) {}

    public function withGeneratedPosition(SourceMapPosition $generated): self
    {
        return new self($generated, $this->original, $this->sourceIndex);
    }
}
