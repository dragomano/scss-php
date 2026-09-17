<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;

/**
 * @phpstan-import-type ChannelVector from ColorSpaceConverter
 *
 * @psalm-import-type ChannelVector from ColorSpaceConverter
 */
final readonly class NativeChannels
{
    /**
     * @param ChannelVector $channels
     */
    public function __construct(
        public array $channels,
        public ?float $alpha,
    ) {}

    /**
     * @param ChannelVector $channels
     */
    public function withChannels(array $channels): self
    {
        return new self($channels, $this->alpha);
    }
}
