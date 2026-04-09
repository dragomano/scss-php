<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

final readonly class AtRuleContextEntry
{
    private function __construct(
        public string $type,
        public ?string $name = null,
        public ?string $prelude = null,
        public ?string $condition = null,
    ) {}

    public static function directive(string $name, string $prelude = ''): self
    {
        return new self('directive', $name, $prelude);
    }

    public static function supports(string $condition): self
    {
        return new self('supports', condition: $condition);
    }
}
