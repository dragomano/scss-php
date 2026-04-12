<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Utils\NameNormalizer;

use function array_key_exists;

final class Scope
{
    private readonly VariableRegistry $variables;

    private readonly CallableDefinitionMap $mixins;

    private readonly CallableDefinitionMap $functions;

    /** @var array<string, Scope> */
    private array $modules = [];

    private ?Scope $globalScope = null;

    public function __construct(private readonly ?Scope $parent = null)
    {
        $this->variables = new VariableRegistry();
        $this->mixins    = new CallableDefinitionMap();
        $this->functions = new CallableDefinitionMap();
    }

    public function getParent(): ?Scope
    {
        return $this->parent;
    }

    public function getGlobalScope(): Scope
    {
        if ($this->globalScope !== null) {
            return $this->globalScope;
        }

        $current = $this;

        while ($current->parent !== null) {
            $current = $current->parent;
        }

        return $this->globalScope = $current;
    }

    public function setVariable(
        string $name,
        AstNode $value,
        bool $global = false,
        bool $default = false,
        int $line = 1,
    ): void {
        $name = $this->normalizeName($name);

        if ($global) {
            $this->getGlobalScope()->setVariableForce($name, $value, $default, $line);

            return;
        }

        if ($default) {
            $existingScope = $this->findScopeForVariable($name);

            if ($existingScope !== null && ! $this->isSassNull($existingScope->variables->get($name))) {
                return;
            }
        }

        $this->variables->set($name, $value, $line);
    }

    public function setVariableLocal(string $name, mixed $value, bool $default = false, int $line = 1): void
    {
        $name = $this->normalizeName($name);

        if ($default) {
            $existingScope = $this->findScopeForVariable($name);

            if ($existingScope !== null && ! $this->isSassNull($existingScope->variables->get($name))) {
                return;
            }
        }

        $this->variables->set($name, $value, $line);
    }

    public function getVariable(string $name): mixed
    {
        return $this->getVariableNormalized($this->normalizeName($name));
    }

    public function getAstVariable(string $name): ?AstNode
    {
        $normalized = $this->normalizeName($name);
        $scope      = $this->findScopeForVariable($normalized);

        if ($scope === null) {
            return null;
        }

        /** @psalm-var mixed $value */
        $value = $scope->variables->get($normalized);

        return $value instanceof AstNode ? $value : null;
    }

    public function getStringVariable(string $name): ?StringNode
    {
        $normalized = $this->normalizeName($name);
        $scope      = $this->findScopeForVariable($normalized);

        if ($scope === null) {
            return null;
        }

        /** @psalm-var mixed $value */
        $value = $scope->variables->get($normalized);

        return $value instanceof StringNode ? $value : null;
    }

    public function getScopeVariable(string $name): ?Scope
    {
        $normalized = $this->normalizeName($name);
        $scope      = $this->findScopeForVariable($normalized);

        if ($scope === null) {
            return null;
        }

        /** @psalm-var mixed $value */
        $value = $scope->variables->get($normalized);

        return $value instanceof self ? $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getVariables(): array
    {
        return $this->variables->all();
    }

    public function hasVariable(string $name): bool
    {
        return $this->hasVariableNormalized($this->normalizeName($name));
    }

    public function findVariableDefinition(string $name): ?VariableDefinition
    {
        return $this->findVariableDefinitionNormalized($this->normalizeName($name));
    }

    public function setMixin(string $name, CallableDefinition $definition, bool $global = false): void
    {
        $name = $this->normalizeName($name);

        if ($global) {
            $this->getGlobalScope()->mixins->set($name, $definition);
        } else {
            $this->mixins->set($name, $definition);
        }
    }

    /**
     * @param array<int, ArgumentNode> $arguments
     * @param array<int, AstNode> $body
     */
    public function defineMixin(
        string $name,
        array $arguments,
        array $body,
        bool $global = false,
        ?Scope $closureScope = null,
        int $line = 1,
    ): void {
        $this->setMixin(
            $name,
            new CallableDefinition($arguments, $body, $closureScope ?? $this, $line),
            $global,
        );
    }

    public function getMixin(string $name): CallableDefinition
    {
        return $this->getMixinNormalized($this->normalizeName($name));
    }

    public function hasMixin(string $name): bool
    {
        return $this->hasMixinNormalized($this->normalizeName($name));
    }

    public function findMixin(string $name): ?ScopedCallableDefinition
    {
        return $this->findMixinNormalized($this->normalizeName($name));
    }

    public function getMixins(): CallableDefinitionMap
    {
        return $this->mixins;
    }

    public function setFunction(string $name, CallableDefinition $definition, bool $global = false): void
    {
        $name = $this->normalizeName($name);

        if ($global) {
            $this->getGlobalScope()->functions->set($name, $definition);
        } else {
            $this->functions->set($name, $definition);
        }
    }

    /**
     * @param array<int, ArgumentNode> $arguments
     * @param array<int, AstNode> $body
     */
    public function defineFunction(
        string $name,
        array $arguments,
        array $body,
        bool $global = false,
        ?Scope $closureScope = null,
        int $line = 1,
    ): void {
        $this->setFunction(
            $name,
            new CallableDefinition($arguments, $body, $closureScope ?? $this, $line),
            $global,
        );
    }

    public function getFunction(string $name): CallableDefinition
    {
        return $this->getFunctionNormalized($this->normalizeName($name));
    }

    public function hasFunction(string $name): bool
    {
        return $this->hasFunctionNormalized($this->normalizeName($name));
    }

    public function findFunction(string $name): ?ScopedCallableDefinition
    {
        return $this->findFunctionNormalized($this->normalizeName($name));
    }

    public function getFunctions(): CallableDefinitionMap
    {
        return $this->functions;
    }

    public function addModule(string $namespace, Scope $moduleScope): void
    {
        $this->modules[$namespace] = $moduleScope;
    }

    public function hasModuleLocal(string $namespace): bool
    {
        return array_key_exists($namespace, $this->modules);
    }

    public function getModule(string $namespace): ?Scope
    {
        return $this->modules[$namespace] ?? $this->parent?->getModule($namespace);
    }

    private function findScopeForVariable(string $name): ?Scope
    {
        $scope = $this;

        do {
            if ($scope->variables->has($name)) {
                return $scope;
            }

            $scope = $scope->parent;
        } while ($scope !== null);

        return null;
    }

    private function getVariableNormalized(string $name): mixed
    {
        $scope = $this->findScopeForVariable($name);

        if ($scope === null) {
            throw UndefinedSymbolException::variable($name);
        }

        return $scope->variables->get($name);
    }

    private function hasVariableNormalized(string $name): bool
    {
        return $this->findScopeForVariable($name) !== null;
    }

    private function findVariableDefinitionNormalized(string $name): ?VariableDefinition
    {
        $scope = $this->findScopeForVariable($name);

        return $scope !== null
            ? new VariableDefinition($scope, $scope->variables->getLine($name))
            : null;
    }

    private function getMixinNormalized(string $name): CallableDefinition
    {
        $definition = $this->mixins->get($name);

        if ($definition !== null) {
            return $definition;
        }

        if ($this->parent) {
            return $this->parent->getMixinNormalized($name);
        }

        throw UndefinedSymbolException::mixin($name);
    }

    private function hasMixinNormalized(string $name): bool
    {
        if ($this->mixins->has($name)) {
            return true;
        }

        return $this->parent?->hasMixinNormalized($name) ?? false;
    }

    private function findMixinNormalized(string $name): ?ScopedCallableDefinition
    {
        $definition = $this->mixins->get($name);

        if ($definition !== null) {
            return new ScopedCallableDefinition($definition, $this);
        }

        return $this->parent?->findMixinNormalized($name);
    }

    private function getFunctionNormalized(string $name): CallableDefinition
    {
        $definition = $this->functions->get($name);

        if ($definition !== null) {
            return $definition;
        }

        if ($this->parent) {
            return $this->parent->getFunctionNormalized($name);
        }

        throw UndefinedSymbolException::function($name);
    }

    private function hasFunctionNormalized(string $name): bool
    {
        if ($this->functions->has($name)) {
            return true;
        }

        return $this->parent?->hasFunctionNormalized($name) ?? false;
    }

    private function findFunctionNormalized(string $name): ?ScopedCallableDefinition
    {
        $definition = $this->functions->get($name);

        if ($definition !== null) {
            return new ScopedCallableDefinition($definition, $this);
        }

        return $this->parent?->findFunctionNormalized($name);
    }

    private function setVariableForce(string $name, mixed $value, bool $default, int $line): void
    {
        if ($default && $this->variables->has($name)) {
            if (! $this->isSassNull($this->variables->get($name))) {
                return;
            }
        }

        $this->variables->set($name, $value, $line);
    }

    private function isSassNull(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        return $value instanceof NullNode;
    }

    private function normalizeName(string $name): string
    {
        return NameNormalizer::normalize($name);
    }
}
