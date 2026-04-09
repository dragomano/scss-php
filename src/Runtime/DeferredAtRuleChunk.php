<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

final readonly class DeferredAtRuleChunk
{
    public function __construct(public int $levels, public string $chunk) {}
}
