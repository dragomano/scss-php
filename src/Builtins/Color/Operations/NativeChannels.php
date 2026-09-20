<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

final readonly class NativeChannels
{
    /**
     * @param array<int, float|null> $channels
     */
    public function __construct(
        public array $channels,
        public ?float $alpha,
    ) {}

    /**
     * @param array<int, float|null> $channels
     */
    public function withChannels(array $channels): self
    {
        return new self($channels, $this->alpha);
    }
}
