<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final readonly class SourceMapPosition
{
    public function __construct(public int $line, public int $column) {}
}
