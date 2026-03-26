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
    /** @var array<string, mixed> */
    private array $variables = [];

    /** @var array<string, int> */
    private array $variableLines = [];

    private readonly CallableDefinitionMap $mixins;

    private readonly CallableDefinitionMap $functions;

    /** @var array<string, Scope> */
    private array $modules = [];

    public function __construct(private readonly ?Scope $parent = null)
    {
        $this->mixins    = new CallableDefinitionMap();
        $this->functions = new CallableDefinitionMap();
    }

    public function getParent(): ?Scope
    {
        return $this->parent;
    }

    public function getGlobalScope(): Scope
    {
        $current = $this;

        while ($current->parent !== null) {
            $current = $current->parent;
        }

        return $current;
    }

    public function setVariable(
        string $name,
        AstNode $value,
        bool $global = false,
        bool $default = false,
        int $line = 1
    ): void {
        $name = $this->normalizeName($name);

        if ($global) {
            $this->getGlobalScope()->setVariableForce($name, $value, $default, $line);

            return;
        }

        if ($default && $this->hasVariable($name) && ! $this->isSassNull($this->getVariable($name))) {
            return;
        }

        $this->variables[$name]     = $value;
        $this->variableLines[$name] = $line;
    }

    public function setVariableLocal(string $name, mixed $value, bool $default = false, int $line = 1): void
    {
        $name = $this->normalizeName($name);

        if ($default && $this->hasVariable($name) && ! $this->isSassNull($this->getVariable($name))) {
            return;
        }

        $this->variables[$name]     = $value;
        $this->variableLines[$name] = $line;
    }

    public function getVariable(string $name): mixed
    {
        $name = $this->normalizeName($name);

        if (array_key_exists($name, $this->variables)) {
            return $this->variables[$name];
        }

        if ($this->parent) {
            return $this->parent->getVariable($name);
        }

        throw UndefinedSymbolException::variable($name);
    }

    public function getAstVariable(string $name): ?AstNode
    {
        if (! $this->hasVariable($name)) {
            return null;
        }

        /** @psalm-var mixed $value */
        $value = $this->getVariable($name);

        if ($value instanceof AstNode) {
            return $value;
        }

        return null;
    }

    public function getStringVariable(string $name): ?StringNode
    {
        if (! $this->hasVariable($name)) {
            return null;
        }

        /** @psalm-var mixed $value */
        $value = $this->getVariable($name);

        if ($value instanceof StringNode) {
            return $value;
        }

        return null;
    }

    public function getScopeVariable(string $name): ?Scope
    {
        if (! $this->hasVariable($name)) {
            return null;
        }

        /** @psalm-var mixed $value */
        $value = $this->getVariable($name);

        if ($value instanceof self) {
            return $value;
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function getVariables(): array
    {
        return $this->variables;
    }

    public function hasVariable(string $name): bool
    {
        $name = $this->normalizeName($name);

        if (array_key_exists($name, $this->variables)) {
            return true;
        }

        return $this->parent !== null && $this->parent->hasVariable($name);
    }

    public function findVariableDefinition(string $name): ?VariableDefinition
    {
        $name = $this->normalizeName($name);

        if (array_key_exists($name, $this->variables)) {
            return new VariableDefinition($this, $this->variableLines[$name] ?? 1);
        }

        return $this->parent?->findVariableDefinition($name);
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
        int $line = 1
    ): void {
        $this->setMixin(
            $name,
            new CallableDefinition($arguments, $body, $closureScope ?? $this, $line),
            $global
        );
    }

    public function getMixin(string $name): CallableDefinition
    {
        $name       = $this->normalizeName($name);
        $definition = $this->mixins->get($name);

        if ($definition !== null) {
            return $definition;
        }

        if ($this->parent) {
            return $this->parent->getMixin($name);
        }

        throw UndefinedSymbolException::mixin($name);
    }

    public function hasMixin(string $name): bool
    {
        $name = $this->normalizeName($name);

        if ($this->mixins->has($name)) {
            return true;
        }

        return $this->parent?->hasMixin($name) ?? false;
    }

    public function findMixin(string $name): ?ScopedCallableDefinition
    {
        $name       = $this->normalizeName($name);
        $definition = $this->mixins->get($name);

        if ($definition !== null) {
            return new ScopedCallableDefinition($definition, $this);
        }

        return $this->parent?->findMixin($name);
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
        int $line = 1
    ): void {
        $this->setFunction(
            $name,
            new CallableDefinition($arguments, $body, $closureScope ?? $this, $line),
            $global
        );
    }

    public function getFunction(string $name): CallableDefinition
    {
        $name       = $this->normalizeName($name);
        $definition = $this->functions->get($name);

        if ($definition !== null) {
            return $definition;
        }

        if ($this->parent) {
            return $this->parent->getFunction($name);
        }

        throw UndefinedSymbolException::function($name);
    }

    public function hasFunction(string $name): bool
    {
        $name = $this->normalizeName($name);

        if ($this->functions->has($name)) {
            return true;
        }

        return $this->parent?->hasFunction($name) ?? false;
    }

    public function findFunction(string $name): ?ScopedCallableDefinition
    {
        $name       = $this->normalizeName($name);
        $definition = $this->functions->get($name);

        if ($definition !== null) {
            return new ScopedCallableDefinition($definition, $this);
        }

        return $this->parent?->findFunction($name);
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

    private function setVariableForce(string $name, mixed $value, bool $default, int $line): void
    {
        $name = $this->normalizeName($name);

        if ($default && array_key_exists($name, $this->variables)) {
            if (! $this->isSassNull($this->variables[$name])) {
                return;
            }
        }

        $this->variables[$name]     = $value;
        $this->variableLines[$name] = $line;
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
