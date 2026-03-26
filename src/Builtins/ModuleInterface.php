<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

interface ModuleInterface
{
    public function getName(): string;

    /**
     * @return array<int, string>
     */
    public function getFunctions(): array;

    /**
     * @return array<string, AstNode>
     */
    public function getVariables(): array;

    /**
     * @return array<string, string>
     */
    public function getGlobalAliases(): array;

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context): AstNode;
}
