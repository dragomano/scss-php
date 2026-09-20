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
use function is_array;
use function str_ends_with;
use function str_starts_with;

final class Scope
{
    private readonly VariableRegistry $variables;

    private ?CallableDefinitionMap $mixins = null;

    private ?CallableDefinitionMap $functions = null;

    /** @var array<string, Scope> */
    private array $modules = [];

    /** @var array<int, array{module: string, prefix: ?string}> */
    private array $forwardedBuiltins = [];

    /** @var array<string, true> */
    private array $importedMembers = [];

    /** @var array<string, array{scope: Scope, name: string}> */
    private array $importedVariables = [];

    /** @var array<string, array{scope: Scope, name: string}> */
    private array $forwardedVariables = [];

    /** @var array<string, AstNode> */
    private array $incomingConfiguration = [];

    /** @var array<string, AstNode> */
    private array $configuredVariables = [];

    private ?Scope $globalScope = null;

    private bool $insideCssFunctionBody = false;

    private bool $flowControlScope = false;

    private bool $callableBody = false;

    private bool $moduleRootScope = false;

    public function __construct(private readonly ?Scope $parent = null)
    {
        $this->variables = new VariableRegistry();
    }

    public function getParent(): ?Scope
    {
        return $this->parent;
    }

    public function setInsideCssFunctionBody(bool $flag): void
    {
        $this->insideCssFunctionBody = $flag;
    }

    public function isInsideCssFunctionBody(): bool
    {
        return $this->insideCssFunctionBody;
    }

    public function isInsideKeyframes(): bool
    {
        if (! $this->hasVariable('__at_rule_stack')) {
            return false;
        }

        $atRuleStack = $this->getVariable('__at_rule_stack');

        if (! is_array($atRuleStack)) {
            return false;
        }

        /** @var list<AtRuleContextEntry|array<string, mixed>> $atRuleStack */
        foreach ($atRuleStack as $entry) {
            if ($entry instanceof AtRuleContextEntry && $entry->name !== null && str_ends_with($entry->name, 'keyframes')) {
                return true;
            }
        }

        return false;
    }

    public function markAsFlowControlScope(): void
    {
        $this->flowControlScope = true;
    }

    public function markAsCallableBody(): void
    {
        $this->callableBody = true;
    }

    public function isFlowControlScope(): bool
    {
        return $this->flowControlScope;
    }

    public function markAsModuleRootScope(): void
    {
        $this->moduleRootScope = true;
    }

    public function isModuleRootScope(): bool
    {
        return $this->moduleRootScope;
    }

    /** @param array<string, AstNode> $configuration */
    public function setIncomingConfiguration(array $configuration): void
    {
        $this->incomingConfiguration = $configuration;
    }

    /** @return array<string, AstNode> */
    public function getIncomingConfiguration(): array
    {
        return $this->incomingConfiguration;
    }

    /** @param array<string, AstNode> $configured */
    public function setConfiguredVariables(array $configured): void
    {
        $normalized = [];

        foreach ($configured as $name => $value) {
            $normalized[$this->normalizeName($name)] = $value;
        }

        $this->configuredVariables = $normalized;
    }

    public function getConfiguredVariable(string $name): ?AstNode
    {
        $normalized = $this->normalizeName($name);
        $scope      = $this;

        do {
            $value = $scope->configuredVariables[$normalized] ?? null;

            if ($value !== null) {
                return $value;
            }

            $scope = $scope->parent;
        } while ($scope !== null);

        return null;
    }

    /** @return array<string, AstNode> */
    public function getConfiguredVariables(): array
    {
        return $this->configuredVariables;
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

            $value = $this->configuredValueFor($name) ?? $value;
        }

        $this->variables->set($name, $value, $line);
    }

    public function setVariableLocal(string $name, mixed $value, bool $default = false, int $line = 1): void
    {
        $isInternal = str_starts_with($name, '__');

        $name = $this->normalizeName($name);

        if (! $default) {
            $existingScope = $this->findScopeForVariable($name);

            if ($existingScope !== null
                && $existingScope !== $this
                && ! $isInternal
                && $this->isWriteThroughTarget($existingScope)
            ) {
                $existingScope->setVariableLocal($name, $value, false, $line);

                return;
            }
        }

        if ($default) {
            $existingScope = $this->findScopeForVariable($name);

            if ($existingScope !== null && ! $this->isSassNull($existingScope->variables->get($name))) {
                return;
            }

            /** @var AstNode|null $configured */
            $configured = $this->configuredValueFor($name);

            /** @var mixed $value */
            $value = $configured ?? $value;
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

    public function isInsideAtRootWithoutRule(): bool
    {
        $withoutRuleName = $this->normalizeName('__at_root_without_rule');
        $parentName      = $this->normalizeName('__parent_selector');
        $scope           = $this;

        while ($scope !== null) {
            if ($scope->variables->has($withoutRuleName)) {
                return true;
            }

            if ($scope->variables->has($parentName)) {
                return false;
            }

            $scope = $scope->parent;
        }

        return false;
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
            $this->getGlobalScope()->getMixins()->set($name, $definition);
        } else {
            $this->getMixins()->set($name, $definition);
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
        return $this->mixins ??= new CallableDefinitionMap();
    }

    public function setFunction(string $name, CallableDefinition $definition, bool $global = false): void
    {
        $name = $this->normalizeName($name);

        if ($global) {
            $this->getGlobalScope()->getFunctions()->set($name, $definition);
        } else {
            $this->getFunctions()->set($name, $definition);
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
        return $this->functions ??= new CallableDefinitionMap();
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

    public function addForwardedBuiltin(string $module, ?string $prefix): void
    {
        $this->forwardedBuiltins[] = ['module' => $module, 'prefix' => $prefix];
    }

    /** @return array<int, array{module: string, prefix: ?string}> */
    public function getForwardedBuiltins(): array
    {
        return $this->forwardedBuiltins;
    }

    public function markImportedMember(string $name): void
    {
        $this->importedMembers[NameNormalizer::normalize($name)] = true;
    }

    public function isImportedMember(string $name): bool
    {
        return array_key_exists(NameNormalizer::normalize($name), $this->importedMembers);
    }

    public function trackImportedVariable(string $name, Scope $originScope, string $originName): void
    {
        $this->importedVariables[NameNormalizer::normalize($name)] = ['scope' => $originScope, 'name' => $originName];
    }

    /** @return array{scope: Scope, name: string}|null */
    public function findImportedVariableOrigin(string $name): ?array
    {
        $normalized = NameNormalizer::normalize($name);
        $scope      = $this;

        do {
            $origin = $scope->importedVariables[$normalized] ?? null;

            if ($origin !== null) {
                return $origin;
            }

            $scope = $scope->parent;
        } while ($scope !== null);

        return null;
    }

    public function trackForwardedVariable(string $name, Scope $originScope, string $originName): void
    {
        $this->forwardedVariables[NameNormalizer::normalize($name)] = ['scope' => $originScope, 'name' => $originName];
    }

    /** @return array{scope: Scope, name: string}|null */
    public function findForwardedVariableOrigin(string $name): ?array
    {
        $normalized = NameNormalizer::normalize($name);

        return $this->forwardedVariables[$normalized] ?? null;
    }

    private function isWriteThroughTarget(Scope $target): bool
    {
        if ($target === $this->getGlobalScope()) {
            return $this->flowControlScope && $this->isSemiGlobalPath($target);
        }

        $scope = $this;

        while ($scope !== null && $scope !== $target) {
            if ($scope->callableBody) {
                return false;
            }

            $scope = $scope->parent;
        }

        return true;
    }

    private function isSemiGlobalPath(Scope $target): bool
    {
        $scope = $this->parent;

        while ($scope !== null && $scope !== $target) {
            if (! $scope->flowControlScope) {
                return false;
            }

            $scope = $scope->parent;
        }

        return $scope === $target;
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

    private function configuredValueFor(string $name): ?AstNode
    {
        $value = $this->getConfiguredVariable($name);

        if ($value === null || $this->isSassNull($value)) {
            return null;
        }

        return $value;
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
        $definition = $this->mixins?->get($name);

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
        if ($this->mixins?->has($name) ?? false) {
            return true;
        }

        return $this->parent?->hasMixinNormalized($name) ?? false;
    }

    private function findMixinNormalized(string $name): ?ScopedCallableDefinition
    {
        $definition = $this->mixins?->get($name);

        if ($definition !== null) {
            return new ScopedCallableDefinition($definition, $this);
        }

        return $this->parent?->findMixinNormalized($name);
    }

    private function getFunctionNormalized(string $name): CallableDefinition
    {
        $definition = $this->functions?->get($name);

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
        if ($this->functions?->has($name) ?? false) {
            return true;
        }

        return $this->parent?->hasFunctionNormalized($name) ?? false;
    }

    private function findFunctionNormalized(string $name): ?ScopedCallableDefinition
    {
        $definition = $this->functions?->get($name);

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

        if ($default) {
            /** @var AstNode|null $configured */
            $configured = $this->configuredValueFor($name);

            /** @var mixed $value */
            $value = $configured ?? $value;
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
