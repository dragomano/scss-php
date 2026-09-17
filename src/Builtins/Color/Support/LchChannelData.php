<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

final readonly class LchChannelData
{
    public function __construct(
        public float $l,
        public float $c,
        public float $h,
        public float $a,
        public bool $lightnessMissing = false,
        public bool $chromaMissing = false,
        public bool $hueMissing = false,
    ) {}

    public function hasMissingChannels(): bool
    {
        return $this->lightnessMissing
            || $this->chromaMissing
            || $this->hueMissing;
    }
}
