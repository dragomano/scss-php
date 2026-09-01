<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Scope;

final class FunctionNode extends AstNode
{
    /**
     * @param array<int, AstNode> $arguments
     */
    public function __construct(
        public string $name,
        public array $arguments = [],
        public int $line = 0,
        public bool $modernSyntax = false,
        public ?Scope $capturedScope = null,
        public ?CallableDefinition $lockedDefinition = null,
        public int $parenthesized = 0,
        public ?string $originColorSpace = null,
        /** @var array{0: float, 1: float, 2: float}|null */
        public ?array $originSrgbChannels = null,
    ) {}
}
