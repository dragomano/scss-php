<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use Bugo\SCSS\Nodes\AstNode;

final readonly class ResolvedCallArguments
{
    /**
     * @param array<int, AstNode>       $positional
     * @param array<string, AstNode>    $named
     */
    public function __construct(
        public array $positional,
        public array $named,
        public string $separator = 'comma',
    ) {}
}
