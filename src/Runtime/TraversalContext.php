<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

final readonly class TraversalContext
{
    public function __construct(public Environment $env, public int $indent = 0) {}
}
