<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Utils\SourceMapMapping;

final class SourceMapState
{
    /** @var array<int, SourceMapMapping> */
    public array $mappings = [];

    public bool $collectMappings = false;

    public int $generatedLine = 1;

    public int $generatedColumn = 0;

    public function startCollection(): void
    {
        $this->reset();

        $this->collectMappings = true;
    }

    public function stopCollection(): void
    {
        $this->collectMappings = false;
    }

    public function reset(): void
    {
        $this->collectMappings = false;
        $this->generatedLine   = 1;
        $this->generatedColumn = 0;
        $this->mappings        = [];
    }
}
