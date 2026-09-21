<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\InvalidArgumentTypeException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\FunctionRefNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\ModuleRefNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\ScopedCallableDefinition;
use Bugo\SCSS\Runtime\VariableDefinition;
use Bugo\SCSS\Utils\NameHelper;
use Bugo\SCSS\Values\AstValueType;
use Bugo\SCSS\Values\SassCalculation;
use LogicException;

use function array_diff;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function str_starts_with;

final class SassMetaModule extends AbstractModule
{
    private const SUPPORTED_FEATURES = [
        'global-variable-shadowing',
        'extend-selector-pseudoclass',
        'units-level-3',
        'at-error',
        'custom-property',
    ];

    private const FUNCTIONS = [
        'accepts-content',
        'calc-args',
        'calc-name',
        'call',
        'content-exists',
        'feature-exists',
        'function-exists',
        'get-function',
        'get-mixin',
        'get-module',
        'global-variable-exists',
        'inspect',
        'keywords',
        'mixin-exists',
        'module-functions',
        'module-mixins',
        'module-variables',
        'type-of',
        'variable-exists',
    ];

    private const BUILTIN_META_MIXINS = [
        'apply',
        'load-css',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const PARAMETER_NAMES = [
        'accepts-content'        => ['mixin'],
        'calc-args'              => ['calc'],
        'calc-name'              => ['calc'],
        'call'                   => ['function'],
        'content-exists'         => [],
        'feature-exists'         => ['feature'],
        'function-exists'        => ['name', 'module'],
        'get-function'           => ['name', 'css', 'module'],
        'get-mixin'              => ['name', 'module'],
        'get-module'             => ['module'],
        'global-variable-exists' => ['name', 'module'],
        'inspect'                => ['value'],
        'keywords'               => ['args'],
        'mixin-exists'           => ['name', 'module'],
        'module-functions'       => ['module'],
        'module-mixins'          => ['module'],
        'module-variables'       => ['module'],
        'type-of'                => ['value'],
        'variable-exists'        => ['name'],
    ];

    public function getName(): string
    {
        return 'meta';
    }

    public function getFunctions(): array
    {
        return self::FUNCTIONS;
    }

    public function getGlobalAliases(): array
    {
        return $this->globalAliases(array_values(array_diff(
            self::FUNCTIONS,
            ['calc-args', 'calc-name', 'get-module'],
        )));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context = null): AstNode
    {
        $previousDisplayName = $this->beginBuiltinCall($name, $context);

        try {
            if ($named !== []) {
                $positional = $this->mergeNamedArguments($positional, $named, self::PARAMETER_NAMES[$name] ?? []);
            }

            return match ($name) {
                'accepts-content'        => $this->acceptsContent($positional, $named, $context),
                'calc-args'              => $this->calcArgs($positional, $named),
                'calc-name'              => $this->calcName($positional, $named),
                'call'                   => $this->callFunction($positional, $named, $context),
                'content-exists'         => $this->contentExists($context),
                'feature-exists'         => $this->featureExists($positional, $context),
                'function-exists'        => $this->functionExists($positional, $named, $context),
                'get-function'           => $this->getFunction($positional, $named, $context),
                'get-mixin'              => $this->getMixin($positional, $named, $context),
                'get-module'             => $this->getModule($positional, $named, $context),
                'global-variable-exists' => $this->globalVariableExists($positional, $named, $context),
                'inspect'                => $this->inspect($positional),
                'keywords'               => $this->keywords($positional, $named),
                'mixin-exists'           => $this->mixinExists($positional, $named, $context),
                'module-functions'       => $this->moduleFunctions($positional, $context),
                'module-mixins'          => $this->moduleMixins($positional, $context),
                'module-variables'       => $this->moduleVariables($positional, $context),
                'type-of'                => $this->typeOf($positional),
                'variable-exists'        => $this->variableExists($positional, $context, $named),
                default                  => throw new UnknownSassFunctionException('meta', $name),
            };
        } finally {
            $this->endBuiltinCall($previousDisplayName);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function acceptsContent(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $mixinArg = $positional[0] ?? $named['mixin'] ?? null;

        if (! ($mixinArg instanceof AstNode)) {
            return $this->boolNode(false);
        }

        $reference = $this->mixinReferenceName($mixinArg);

        if ($reference === null) {
            return $this->boolNode(false);
        }

        $scope     = $this->scopeFromContext($context);
        $mixinBody = $this->resolveMixinBody($scope, $reference);

        if ($mixinBody === null) {
            if ($reference === 'meta.apply') {
                return $this->boolNode(true);
            }

            return $this->boolNode(false);

        }

        return $this->boolNode($this->mixinBodyAcceptsContent($mixinBody));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function calcArgs(array $positional, array $named): AstNode
    {
        $calc = $positional[0] ?? $named['calc'] ?? null;

        if (! ($calc instanceof FunctionNode)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.calc-args'),
                'a calculation function value',
            );
        }

        $args = [];

        foreach ($calc->arguments as $argument) {
            if ($argument instanceof NumberNode) {
                $args[] = $argument;
            } elseif ($argument instanceof FunctionNode && SassCalculation::isCalculationFunctionName($argument->name)) {
                $args[] = $argument;
            } else {
                $args[] = new StringNode($this->formatValue($argument), false);
            }
        }

        return new ListNode($args, 'comma');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function calcName(array $positional, array $named): AstNode
    {
        $calc = $positional[0] ?? $named['calc'] ?? null;

        if (! ($calc instanceof FunctionNode)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.calc-name'),
                'a calculation function value',
            );
        }

        return new StringNode($calc->name, true);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function callFunction(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        if (isset($named['function']) && ($positional[0] ?? null) === $named['function']) {
            unset($named['function']);
        }

        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.call'),
                'a function value and optional arguments',
            );
        }

        $name = $this->functionNameFromValue($positional[0]);

        if ($name === null || $context === null || $context->registry === null) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.call'),
                'a function value',
            );
        }

        $original      = $positional[0];
        $qualifiedName = $name;

        if ($original instanceof FunctionRefNode && $original->module !== null) {
            $qualifiedName = $original->module . '.' . $name;
        }

        $arguments = array_slice($positional, 1);

        if ($original instanceof FunctionRefNode && $original->css) {
            $parts = [];

            foreach ($arguments as $argument) {
                $parts[] = $this->formatValue($argument);
            }

            return new StringNode($name . '(' . implode(', ', $parts) . ')');
        }

        foreach ($named as $argumentName => $value) {
            $arguments[] = new NamedArgumentNode($argumentName, $value);
        }

        $registry = $context->registry;
        $result   = $registry->tryCall($qualifiedName, $arguments, $context);

        if ($result === null) {
            $capturedScope    = null;
            $lockedDefinition = null;

            if ($original instanceof FunctionRefNode) {
                $lockedDefinition = $original->lockedDefinition;
            } elseif ($original instanceof FunctionNode) {
                $capturedScope    = $original->capturedScope;
                $lockedDefinition = $original->lockedDefinition;
            }

            return new FunctionNode(
                $name,
                $arguments,
                capturedScope: $capturedScope,
                lockedDefinition: $lockedDefinition,
            );
        }

        return $result;
    }

    private function contentExists(?BuiltinCallContext $context): AstNode
    {
        $scope = $this->scopeFromContext($context);

        if (! $scope->hasVariable('__meta_content_exists')) {
            return $this->boolNode(false);
        }

        $value = $scope->getAstVariable('__meta_content_exists');

        return $this->boolNode($value instanceof BooleanNode && $value->value);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function featureExists(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1 || ! ($positional[0] instanceof StringNode)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.feature-exists'),
                'a feature name string',
            );
        }

        $this->warnAboutDeprecatedMetaFunction($context, 'feature-exists', $positional);

        return $this->boolNode(in_array(
            $positional[0]->value,
            self::SUPPORTED_FEATURES,
            true,
        ));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function functionExists(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedMetaFunction($context, 'function-exists', $positional);

        $name   = $this->requiredString($positional, 'meta.function-exists');
        $scope  = $this->scopeFromContext($context);
        $module = $this->resolveModule($positional, $named, $scope);

        if ($module !== null) {
            $moduleName            = $module->name;
            $hasBuiltinModuleAlias = $moduleName !== null && $context?->registry?->resolveModuleAlias($moduleName) !== null;

            if (! $hasBuiltinModuleAlias && $module->scope === null) {
                throw ModuleResolutionException::unknownNamespace($moduleName ?? '');
            }

            if ($moduleName !== null && $context?->registry?->hasFunction($name, $moduleName) === true) {
                return $this->boolNode(true);
            }

            return $this->boolNode($module->scope?->hasFunction($name) ?? false);
        }

        $hasUser    = $this->functionIsVisibleAtCallSite($scope, $name, $context);
        $hasBuiltin = $context?->registry?->hasFunction($name) === true;

        return $this->boolNode($hasUser || $hasBuiltin);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function getFunction(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $name = $this->requiredString($positional, 'meta.get-function');

        $cssValue = $named['css'] ?? null;

        if ($cssValue instanceof BooleanNode && $cssValue->value) {
            return new FunctionRefNode($name, css: true);
        }

        $scope  = $this->scopeFromContext($context);
        $module = $this->resolveModuleNode($named['module'] ?? null, $scope);

        if ($module !== null) {
            $moduleName  = $module->name;
            $hasBuiltin  = $moduleName !== null && $context?->registry?->hasFunction($name, $moduleName) === true;
            $moduleScope = $module->scope;

            if ($moduleScope !== null && $moduleScope->hasFunction($name)) {
                if (! $hasBuiltin) {
                    $lockedDefinition = $moduleScope->findFunction($name)?->definition;

                    return new FunctionRefNode($name, $moduleName, $lockedDefinition, $moduleScope);
                }

                return new FunctionRefNode($name, $moduleName);
            }

            if (! $hasBuiltin) {
                throw ModuleResolutionException::callableNotFound(
                    $this->builtinErrorContext('meta.get-function'),
                    $name,
                    $moduleName,
                );
            }

            return new FunctionRefNode($name, module: $moduleName);
        }

        $hasBuiltin = $context?->registry?->hasFunction($name) === true;
        $hasUser    = $scope->hasFunction($name);

        if (! $hasBuiltin && ! $hasUser) {
            throw ModuleResolutionException::callableNotFound(
                $this->builtinErrorContext('meta.get-function'),
                $name,
            );
        }

        if ($hasUser && ! $hasBuiltin) {
            $lockedDefinition = $scope->findFunction($name)?->definition;

            return new FunctionRefNode($name, lockedDefinition: $lockedDefinition, capturedScope: $scope);
        }

        return new FunctionRefNode($name);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function getMixin(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $name   = $this->requiredString($positional, 'meta.get-mixin');
        $scope  = $this->scopeFromContext($context);
        $module = $this->resolveModule($positional, $named, $scope);

        if ($module !== null) {
            $moduleScope = $module->scope;
            $moduleName  = $module->name;

            if ($moduleScope !== null && $moduleScope->hasMixin($name)) {
                $lockedDefinition = $moduleScope->findMixin($name)?->definition;
                $refName          = $moduleName !== null ? $moduleName . '.' . $name : $name;

                return new MixinRefNode($refName, lockedDefinition: $lockedDefinition);
            }

            if ($moduleName === 'meta' && in_array($name, self::BUILTIN_META_MIXINS, true)) {
                return new MixinRefNode($moduleName . '.' . $name);
            }

            throw ModuleResolutionException::callableNotFound(
                $this->builtinErrorContext('meta.get-mixin'),
                $name,
                $moduleName,
            );
        }

        if (! $scope->hasMixin($name)) {
            throw ModuleResolutionException::callableNotFound(
                $this->builtinErrorContext('meta.get-mixin'),
                $name,
            );
        }

        $lockedDefinition = $scope->findMixin($name)?->definition;

        return new MixinRefNode($name, lockedDefinition: $lockedDefinition);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function getModule(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $name  = $this->requiredString($positional, 'meta.get-module');
        $scope = $this->scopeFromContext($context);

        $builtinName = $context?->registry?->resolveModuleAlias($name);
        $moduleScope = $scope->getModule($name);

        if ($builtinName !== null) {
            return new ModuleRefNode(scope: $moduleScope, builtinName: $builtinName);
        }

        if ($moduleScope !== null) {
            return new ModuleRefNode(scope: $moduleScope);
        }

        throw ModuleResolutionException::unknownNamespace($name);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function globalVariableExists(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedMetaFunction($context, 'global-variable-exists', $positional);

        $name   = $this->requiredString($positional, 'meta.global-variable-exists');
        $scope  = $this->scopeFromContext($context);
        $module = $this->resolveModule($positional, $named, $scope);

        if ($module !== null) {
            $moduleScope = $this->requireModuleScope($module);

            return $this->boolNode($moduleScope->hasVariable($name));
        }

        return $this->boolNode($this->globalVariableIsVisibleAtCallSite($scope, $name, $context));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function inspect(array $positional): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.inspect'),
                'a value argument',
            );
        }

        return new StringNode($this->formatForInspect($positional[0]));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function keywords(array $positional, array $named): AstNode
    {
        $value = $positional[0] ?? $named['args'] ?? null;

        if ($value === null) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.keywords'),
                'an argument list value',
            );
        }

        if ($value instanceof ArgumentListNode) {
            $pairs = [];

            foreach ($value->keywords as $name => $keywordValue) {
                $pairs[] = new MapPair(new StringNode($name), $keywordValue);
            }

            return new MapNode($pairs);
        }

        if ($value instanceof MapNode) {
            return $value;
        }

        return new MapNode([]);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function mixinExists(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedMetaFunction($context, 'mixin-exists', $positional);

        $name   = $this->requiredString($positional, 'meta.mixin-exists');
        $scope  = $this->scopeFromContext($context);
        $module = $this->resolveModule($positional, $named, $scope);

        if ($module !== null) {
            $moduleScope = $this->requireModuleScope($module);

            return $this->boolNode($moduleScope->hasMixin($name));
        }

        return $this->boolNode($this->mixinIsVisibleAtCallSite($scope, $name, $context));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function moduleFunctions(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $module     = $this->requireModule($positional, $context, 'meta.module-functions');
        $moduleName = $module->name;
        $scope      = $this->scopeFromContext($context);

        if ($moduleName !== null && $context !== null && $context->registry !== null) {
            $registry         = $context->registry;
            $builtinFunctions = $registry->moduleFunctionsByAlias($moduleName);

            if ($builtinFunctions !== null) {
                $pairs = [];

                foreach ($builtinFunctions as $function) {
                    $pairs[] = new MapPair(
                        new StringNode($function, true),
                        new FunctionNode($moduleName . '.' . $function, capturedScope: $scope),
                    );
                }

                return new MapNode($pairs);
            }
        }

        $moduleScope = $this->requireModuleScope($module);

        $pairs = [];

        foreach ($moduleScope->getFunctions() as $name => $_function) {
            $pairs[] = new MapPair(
                new StringNode($name, true),
                new FunctionRefNode($name, $moduleName, $moduleScope->findFunction($name)?->definition, $moduleScope),
            );
        }

        return new MapNode($pairs);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function moduleMixins(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $module     = $this->requireModule($positional, $context, 'meta.module-mixins');
        $moduleName = $module->name;
        $isMeta     = $moduleName !== null && $context?->registry?->resolveModuleAlias($moduleName) === 'meta';
        $scope      = $module->scope;

        if ($scope === null && ! $isMeta) {
            throw ModuleResolutionException::unknownNamespace($moduleName ?? '');
        }

        $prefix = $moduleName !== null ? $moduleName . '.' : '';
        $pairs  = [];

        if ($isMeta) {
            foreach (self::BUILTIN_META_MIXINS as $name) {
                $pairs[] = new MapPair(new StringNode($name, true), new MixinRefNode($prefix . $name));
            }
        }

        foreach ($scope?->getMixins() ?? [] as $name => $_mixin) {
            $pairs[] = new MapPair(
                new StringNode($name, true),
                new MixinRefNode(
                    $prefix . $name,
                    lockedDefinition: $scope?->findMixin($name)?->definition,
                ),
            );
        }

        return new MapNode($pairs);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function moduleVariables(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $module = $this->requireModule($positional, $context, 'meta.module-variables');
        $scope  = $this->requireModuleScope($module);

        $pairs = [];

        foreach ($scope->getVariables() as $name => $value) {
            if (! $value instanceof AstNode) {
                continue;
            }

            $pairs[] = new MapPair(new StringNode($name, true), $value);
        }

        return new MapNode($pairs);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function typeOf(array $positional): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.type-of'),
                'a value argument',
            );
        }

        $value = $positional[0];

        if ($value instanceof MapNode && $value->isEmptyList) {
            return new StringNode(AstValueType::List->value);
        }

        return new StringNode($this->astType($value));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function variableExists(array $positional, ?BuiltinCallContext $context, array $named = []): AstNode
    {
        $this->warnAboutDeprecatedMetaFunction($context, 'variable-exists', $positional);

        $name   = $this->requiredString($positional, 'meta.variable-exists');
        $scope  = $this->scopeFromContext($context);
        $module = $this->resolveModule($positional, $named, $scope);

        if ($module !== null) {
            $moduleScope = $this->requireModuleScope($module);

            return $this->boolNode($moduleScope->hasVariable($name));
        }

        return $this->boolNode($this->variableIsVisibleAtCallSite($scope, $name, $context));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function warnAboutDeprecatedMetaFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional,
    ): void {
        if (! $this->isGlobalBuiltinCall()) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            'meta.' . $name . '(' . implode(', ', $this->describeArguments($positional)) . ')',
            'meta.' . $name,
        );
    }

    /**
     * @return array<int, AstNode>|null
     */
    private function resolveMixinBody(Scope $scope, string $reference): ?array
    {
        if (NameHelper::hasNamespace($reference)) {
            $parts = NameHelper::splitNamespacedName($reference);

            $moduleScope = $scope->getModule($parts['namespace']);

            if ($moduleScope === null || ! $moduleScope->hasMixin($parts['member'])) {
                return null;
            }

            $mixinData = $moduleScope->getMixin($parts['member']);

            return $mixinData->body;
        }

        if (! $scope->hasMixin($reference)) {
            return null;
        }

        $mixinData = $scope->getMixin($reference);

        return $mixinData->body;
    }

    /**
     * @param array<array-key, AstNode> $body
     */
    private function mixinBodyAcceptsContent(array $body): bool
    {
        foreach ($body as $node) {
            if ($node instanceof DirectiveNode && $node->name === 'content') {
                return true;
            }

            if ($this->nodeContainsContentDirective($node)) {
                return true;
            }
        }

        return false;
    }

    private function nodeContainsContentDirective(AstNode $node): bool
    {
        if (
            $node instanceof DirectiveNode
            || $node instanceof EachNode
            || $node instanceof ForNode
            || $node instanceof WhileNode
            || $node instanceof SupportsNode
            || $node instanceof AtRootNode
        ) {
            return $this->mixinBodyAcceptsContent($node->body);
        }

        if ($node instanceof IfNode) {
            if ($this->mixinBodyAcceptsContent($node->body) || $this->mixinBodyAcceptsContent($node->elseBody)) {
                return true;
            }

            foreach ($node->elseIfBranches as $branch) {
                if ($this->mixinBodyAcceptsContent($branch->body)) {
                    return true;
                }
            }
        }

        if ($node instanceof RuleNode) {
            return $this->mixinBodyAcceptsContent($node->children);
        }

        return false;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function requiredString(array $positional, string $context): string
    {
        if (! isset($positional[0]) || ! ($positional[0] instanceof StringNode)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'a string argument',
            );
        }

        return $positional[0]->value;
    }

    private function mixinReferenceName(AstNode $value): ?string
    {
        if ($value instanceof MixinRefNode) {
            return $value->name;
        }

        if ($value instanceof StringNode) {
            return $value->value;
        }

        return null;
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, string>
     */
    private function describeArguments(array $arguments): array
    {
        return array_map($this->formatValue(...), $arguments);
    }

    private function functionNameFromValue(AstNode $value): ?string
    {
        if ($value instanceof FunctionRefNode) {
            return $value->name;
        }

        if ($value instanceof FunctionNode) {
            return $value->name;
        }

        if ($value instanceof StringNode) {
            return $value->value;
        }

        return null;
    }

    private function functionIsVisibleAtCallSite(Scope $scope, string $name, ?BuiltinCallContext $context): bool
    {
        return $this->isDeclaredBeforeCall($scope->findFunction($name), $context, true);
    }

    private function globalVariableIsVisibleAtCallSite(Scope $scope, string $name, ?BuiltinCallContext $context): bool
    {
        return $this->isDeclaredBeforeCall($scope->getGlobalScope()->findVariableDefinition($name), $context);
    }

    private function mixinIsVisibleAtCallSite(Scope $scope, string $name, ?BuiltinCallContext $context): bool
    {
        return $this->isDeclaredBeforeCall($scope->findMixin($name), $context, true);
    }

    private function variableIsVisibleAtCallSite(Scope $scope, string $name, ?BuiltinCallContext $context): bool
    {
        return $this->isDeclaredBeforeCall($scope->findVariableDefinition($name), $context);
    }

    private function isDeclaredBeforeCall(
        ScopedCallableDefinition|VariableDefinition|null $definition,
        ?BuiltinCallContext $context,
        bool $allowCapturedScope = false,
    ): bool {
        if ($definition === null) {
            return false;
        }

        $callLine = $context?->callLine;

        if ($callLine === null) {
            return true;
        }

        if ($allowCapturedScope && $definition instanceof ScopedCallableDefinition) {
            if ($definition->isCapturedOutsideScope()) {
                return true;
            }

            return $definition->line() < $callLine;
        }

        return $definition->line() < $callLine;
    }

    private function astType(AstNode $value): string
    {
        return AstValueType::fromNode($value)->value;
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function resolveModule(array $positional, array $named, Scope $scope): ?ResolvedModule
    {
        return $this->resolveModuleNode($named['module'] ?? ($positional[1] ?? null), $scope);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function requireModule(array $positional, ?BuiltinCallContext $context, string $errorContext): ResolvedModule
    {
        $module = $this->resolveModuleNode($positional[0] ?? null, $this->scopeFromContext($context));

        if ($module === null) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($errorContext),
                'a string argument',
            );
        }

        return $module;
    }

    private function resolveModuleNode(?AstNode $moduleNode, Scope $scope): ?ResolvedModule
    {
        if ($moduleNode === null) {
            return null;
        }

        if ($moduleNode instanceof ModuleRefNode) {
            return new ResolvedModule($moduleNode->scope, $moduleNode->builtinName);
        }

        if (! ($moduleNode instanceof StringNode)) {
            throw new InvalidArgumentTypeException(
                'meta module argument',
                'string',
                get_debug_type($moduleNode),
            );
        }

        return new ResolvedModule($scope->getModule($moduleNode->value), $moduleNode->value);
    }

    private function requireModuleScope(ResolvedModule $module): Scope
    {
        if ($module->scope === null) {
            throw ModuleResolutionException::unknownNamespace($module->name ?? '');
        }

        return $module->scope;
    }

    private function scopeFromContext(?BuiltinCallContext $context): Scope
    {
        if ($context === null || $context->environment === null) {
            throw new LogicException('Meta functions require compiler evaluation context.');
        }

        $environment = $context->environment;

        return $environment->getCurrentScope();
    }

    private function formatValue(AstNode $node): string
    {
        return $this->valueFactory()->fromAst($node)->toCss();
    }

    private function formatForInspect(AstNode $node): string
    {
        if ($node instanceof ArgumentListNode) {
            return $this->inspectList(new ListNode($node->items, $node->separator, $node->bracketed));
        }

        if ($node instanceof ListNode) {
            return $this->inspectList($node);
        }

        if ($node instanceof MapNode) {
            return $this->inspectMap($node);
        }

        return $this->formatValue($node);
    }

    private function inspectList(ListNode $node): string
    {
        $items = [];

        foreach ($node->items as $item) {
            $items[] = $this->inspectListItem($item, $node->separator);
        }

        if ($items === []) {
            return $node->bracketed ? '[]' : '()';
        }

        $sep = match ($node->separator) {
            'comma' => ', ',
            'slash' => ' / ',
            default => ' ',
        };

        $result = implode($sep, $items);

        if ($node->bracketed) {
            if (count($items) === 1 && $node->separator === 'comma') {
                return '[' . $result . ',]';
            }

            return '[' . $result . ']';
        }

        if (count($items) === 1) {
            if ($node->separator === 'comma') {
                return '(' . $result . ',)';
            }

            if ($node->separator === 'slash') {
                return '(' . $items[0] . '/)';
            }
        }

        return $result;
    }

    private function inspectListItem(AstNode $node, string $parentSeparator): string
    {
        if ($node instanceof ListNode) {
            $inner = $this->inspectList($node);

            if (str_starts_with($inner, '(') || str_starts_with($inner, '[')) {
                return $inner;
            }

            if ($node->separator === 'comma' || $node->parenthesized || $parentSeparator === 'space') {
                return '(' . $inner . ')';
            }

            return $inner;
        }

        if ($node instanceof MapNode) {
            return $this->inspectMap($node);
        }

        return $this->formatValue($node);
    }

    private function inspectMap(MapNode $node): string
    {
        $parts = [];

        foreach ($node->pairs as $pair) {
            $parts[] = $this->inspectMapItem($pair->key) . ': ' . $this->inspectMapItem($pair->value);
        }

        return '(' . implode(', ', $parts) . ')';
    }

    private function inspectMapItem(AstNode $node): string
    {
        if ($node instanceof ArgumentListNode) {
            $node = new ListNode($node->items, $node->separator, $node->bracketed);
        }

        if ($node instanceof ListNode && $node->separator === 'comma' && ! $node->bracketed) {
            return '(' . $this->inspectList($node) . ')';
        }

        return $this->formatForInspect($node);
    }
}
