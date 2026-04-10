<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final readonly class RawChunk implements OutputChunk
{
    public function __construct(private string $content) {}

    public function content(): string
    {
        return $this->content;
    }
}
