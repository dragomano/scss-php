<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use Bugo\SCSS\Nodes\AstNode;

use function array_pop;
use function count;

final class Environment
{
    /** @var array<int, Scope> */
    private array $scopeStack = [];

    public function __construct(private Scope $currentScope = new Scope()) {}

    public function enterScope(?Scope $parent = null): void
    {
        $this->scopeStack[] = $this->currentScope;
        $this->currentScope = $this->createChildScope($parent);
    }

    public function exitScope(): void
    {
        if ($this->scopeStack !== []) {
            $poppedScope = array_pop($this->scopeStack);

            $this->currentScope = $poppedScope;

            return;
        }

        if ($this->currentScope->getParent() !== null) {
            $this->currentScope = $this->currentScope->getParent();
        }
    }

    public function getCurrentScope(): Scope
    {
        return $this->currentScope;
    }

    public function getGlobalScope(): Scope
    {
        return $this->currentScope->getGlobalScope();
    }

    public function findAstVariableInStackGlobals(string $name): ?AstNode
    {
        for ($i = count($this->scopeStack) - 1; $i >= 0; $i--) {
            $value = $this->scopeStack[$i]->getGlobalScope()->getAstVariable($name);

            if ($value !== null) {
                return $value;
            }
        }

        return null;
    }

    private function createChildScope(?Scope $parent): Scope
    {
        return new Scope($parent ?? $this->currentScope);
    }
}
