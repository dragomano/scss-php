<?php

declare(strict_types=1);

namespace Bugo\SCSS\Normalizers;

use Bugo\SCSS\Syntax;

interface SourceNormalizer
{
    public function supports(Syntax $syntax): bool;

    public function normalize(string $source): string;
}
