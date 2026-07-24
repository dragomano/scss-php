<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

final class OutputRenderer
{
    /**
     * @param array<int, string> $indentCache
     * @pest-mutate-ignore
     */
    public function __construct(
        public array $indentCache = [0 => ''],
        public string $separator = "\n",
    ) {}
}
