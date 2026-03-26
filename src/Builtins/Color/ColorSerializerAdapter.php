<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\Iris\Serializers\Serializer;
use Bugo\SCSS\Contracts\Color\ColorSerializerInterface;

final readonly class ColorSerializerAdapter implements ColorSerializerInterface
{
    public function __construct(private Serializer $delegate = new Serializer()) {}

    public function serialize(string $value, bool $outputHexColors): string
    {
        return $this->delegate->serialize($value, $outputHexColors);
    }
}
