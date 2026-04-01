<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Builtins\Color\ColorBundleAdapter;
use Bugo\SCSS\Contracts\Color\ColorBundleInterface;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Utils\NameHelper;
use Bugo\SCSS\Utils\NameNormalizer;
use Closure;

use function in_array;
use function is_string;
use function str_starts_with;
use function substr;

final class FunctionRegistry
{
    /** @var array<string, ModuleInterface> */
    private array $modules = [];

    /** @var array<string, class-string<ModuleInterface>|Closure(): ModuleInterface> */
    private array $moduleFactories = [];

    /** @var array<string, string> */
    private array $moduleAliases = [];

    /** @var array<string, array{0: string, 1: string}> */
    private array $globalAliases = [];

    private const DEFAULT_MODULE_CLASSES = [
        'color'    => SassColorModule::class,
        'list'     => SassListModule::class,
        'map'      => SassMapModule::class,
        'math'     => SassMathModule::class,
        'meta'     => SassMetaModule::class,
        'selector' => SassSelectorModule::class,
        'string'   => SassStringModule::class,
    ];

    /**
     * @param iterable<ModuleInterface> $modules
     */
    public function __construct(
        iterable $modules = [],
        private readonly ColorBundleInterface $colorBundle = new ColorBundleAdapter()
    ) {
        foreach (self::DEFAULT_MODULE_CLASSES as $moduleName => $class) {
            $this->moduleFactories[$moduleName] = $class;
        }

        $this->moduleFactories['color'] = fn(): SassColorModule => new SassColorModule(bundle: $this->colorBundle);

        foreach ($modules as $module) {
            $this->registerModule($module);
        }
    }

    public function registerModule(ModuleInterface $module): void
    {
        $moduleName = $module->getName();

        $this->modules[$moduleName] = $module;

        foreach ($module->getGlobalAliases() as $global => $function) {
            $this->globalAliases[$this->normalizeName($global)] = [$moduleName, $function];
        }
    }

    public function reset(): void
    {
        $this->moduleAliases = [];
    }

    public function registerUse(string $path, ?string $namespace): void
    {
        if (! str_starts_with($path, 'sass:')) {
            return;
        }

        $moduleName = substr($path, 5);

        if (! $this->hasModule($moduleName)) {
            return;
        }

        $alias = $namespace ?? $moduleName;

        if ($alias === '*') {
            return;
        }

        $this->moduleAliases[$alias] = $moduleName;
    }

    /**
     * @param array<int, AstNode|NamedArgumentNode> $arguments
     */
    public function tryCall(string $name, array $arguments, ?BuiltinCallContext $context = null): ?AstNode
    {
        [$positional, $named] = $this->splitArguments($arguments);

        $callContext = $this->withBuiltinDisplayName($context, $name);

        if (NameHelper::hasNamespace($name)) {
            $parts = NameHelper::splitNamespacedName($name);

            $alias    = $parts['namespace'];
            $function = $this->normalizeName($parts['member']);

            $moduleName = $this->moduleAliases[$alias] ?? null;

            if ($moduleName === null) {
                return null;
            }

            $module = $this->getModule($moduleName);

            if ($module === null) {
                return null;
            }

            if (! in_array($function, $module->getFunctions(), true)) {
                return null;
            }

            return $module->call($function, $positional, $named, $callContext);
        }

        $target = $this->resolveGlobalAlias($name);

        if ($target === null) {
            return null;
        }

        [$moduleName, $function] = $target;

        $module = $this->getModule($moduleName);

        if ($module === null) {
            return null;
        }

        try {
            return $module->call($function, $positional, $named, $callContext);
        } catch (DeferToCssFunctionException) {
            return null;
        }
    }

    public function hasFunction(string $functionName, ?string $moduleAlias = null): bool
    {
        if ($moduleAlias !== null) {
            $module = $this->resolveModuleByAlias($moduleAlias);

            if ($module === null) {
                return false;
            }

            $normalizedName = $this->normalizeName($functionName);

            foreach ($module->getFunctions() as $function) {
                if ($this->normalizeName($function) === $normalizedName) {
                    return true;
                }
            }

            return false;
        }

        if (isset($this->globalAliases[$this->normalizeName($functionName)])) {
            return true;
        }

        return $this->resolveGlobalAlias($functionName) !== null;
    }

    /**
     * @return array<int, string>|null
     */
    public function moduleFunctionsByAlias(string $alias): ?array
    {
        $module = $this->resolveModuleByAlias($alias);

        return $module?->getFunctions();
    }

    /**
     * @return array<string, AstNode>|null
     */
    public function moduleVariablesByAlias(string $alias): ?array
    {
        $module = $this->resolveModuleByAlias($alias);

        return $module?->getVariables();
    }

    public function isBuiltinAlias(string $alias): bool
    {
        return isset($this->moduleAliases[$alias]);
    }

    public function resolveModuleAlias(string $alias): ?string
    {
        return $this->moduleAliases[$alias] ?? null;
    }

    /**
     * @param array<int, AstNode|NamedArgumentNode> $arguments
     * @return array{0: array<int, AstNode>, 1: array<string, AstNode>}
     */
    private function splitArguments(array $arguments): array
    {
        $positional = [];
        $named      = [];

        foreach ($arguments as $argument) {
            if ($argument instanceof NamedArgumentNode) {
                $named[$argument->name] = $argument->value;

                continue;
            }

            $positional[] = $argument;
        }

        return [$positional, $named];
    }

    private function hasModule(string $moduleName): bool
    {
        return isset($this->modules[$moduleName]) || isset($this->moduleFactories[$moduleName]);
    }

    private function getModule(string $moduleName): ?ModuleInterface
    {
        if (isset($this->modules[$moduleName])) {
            return $this->modules[$moduleName];
        }

        $factory = $this->moduleFactories[$moduleName] ?? null;

        if ($factory === null) {
            return null;
        }

        $module = is_string($factory) ? new $factory() : $factory();

        $this->registerModule($module);

        return $module;
    }

    private function resolveModuleByAlias(string $alias): ?ModuleInterface
    {
        return $this->getModule($this->moduleAliases[$alias] ?? $alias);
    }

    /**
     * @return array{0: string, 1: string}|null
     */
    private function resolveGlobalAlias(string $functionName): ?array
    {
        $functionName = $this->normalizeName($functionName);

        if (isset($this->globalAliases[$functionName])) {
            return $this->globalAliases[$functionName];
        }

        foreach (self::DEFAULT_MODULE_CLASSES as $moduleName => $_class) {
            if (isset($this->globalAliases[$functionName])) {
                break;
            }

            $this->getModule($moduleName);
        }

        return $this->globalAliases[$functionName] ?? null;
    }

    private function withBuiltinDisplayName(?BuiltinCallContext $context, string $name): BuiltinCallContext
    {
        if ($context !== null) {
            return $context->withBuiltinDisplayName($name);
        }

        return new BuiltinCallContext(builtinDisplayName: $name);
    }

    private function normalizeName(string $name): string
    {
        return NameNormalizer::normalize($name);
    }
}
