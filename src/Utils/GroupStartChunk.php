<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final readonly class GroupStartChunk implements OutputChunk
{
    public function __construct(
        private OutputChunk $inner,
        public bool $isNested = false,
        public bool $fromInclude = false,
        public bool $isEarly = false,
    ) {}

    public function inner(): OutputChunk
    {
        return $this->inner;
    }

    public function content(): string
    {
        return $this->inner->content();
    }
}
