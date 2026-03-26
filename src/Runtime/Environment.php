<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use function array_pop;

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

    private function createChildScope(?Scope $parent): Scope
    {
        return new Scope($parent ?? $this->currentScope);
    }
}
