<?php

declare(strict_types=1);

namespace Bugo\SCSS\Contracts\Color;

interface ColorSerializerInterface
{
    public function serialize(string $value, bool $outputHexColors): string;
}
