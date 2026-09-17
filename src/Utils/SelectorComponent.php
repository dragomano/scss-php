<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final readonly class SelectorComponent
{
    public function __construct(
        public string $sel,
        public string $comb,
        public ?string $lead = null,
    ) {}
}
