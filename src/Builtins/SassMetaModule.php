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
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\ScopedCallableDefinition;
use Bugo\SCSS\Runtime\VariableDefinition;
use Bugo\SCSS\Utils\NameHelper;
use Bugo\SCSS\Utils\NameNormalizer;
use Bugo\SCSS\Values\AstValueType;
use Bugo\SCSS\Values\SassFunctionRef;
use LogicException;

use function array_slice;
use function count;
use function get_debug_type;
use function implode;
use function in_array;

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
        return $this->globalAliases(self::FUNCTIONS);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context = null): AstNode
    {
        $previousDisplayName = $this->beginBuiltinCall($name, $context);

        try {
            return match ($name) {
                'accepts-content'        => $this->acceptsContent($positional, $context),
                'calc-args'              => $this->calcArgs($positional),
                'calc-name'              => $this->calcName($positional),
                'call'                   => $this->callFunction($positional, $context),
                'content-exists'         => $this->contentExists($context),
                'feature-exists'         => $this->featureExists($positional, $context),
                'function-exists'        => $this->functionExists($positional, $named, $context),
                'get-function'           => $this->getFunction($positional, $named, $context),
                'get-mixin'              => $this->getMixin($positional, $named, $context),
                'global-variable-exists' => $this->globalVariableExists($positional, $named, $context),
                'inspect'                => $this->inspect($positional),
                'keywords'               => $this->keywords($positional),
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
     */
    private function acceptsContent(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (! isset($positional[0])) {
            return $this->boolNode(false);
        }

        $reference = $this->mixinReferenceName($positional[0]);

        if ($reference === null) {
            return $this->boolNode(false);
        }

        $scope     = $this->scopeFromContext($context);
        $mixinBody = $this->resolveMixinBody($scope, $reference);

        if ($mixinBody === null) {
            return $this->boolNode(false);
        }

        return $this->boolNode($this->mixinBodyAcceptsContent($mixinBody));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function calcArgs(array $positional): AstNode
    {
        if (count($positional) < 1 || ! ($positional[0] instanceof FunctionNode)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.calc-args'),
                'a calculation function value',
            );
        }

        return new ListNode($positional[0]->arguments, 'comma');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function calcName(array $positional): AstNode
    {
        if (count($positional) < 1 || ! ($positional[0] instanceof FunctionNode)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.calc-name'),
                'a calculation function value',
            );
        }

        return new StringNode($positional[0]->name);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function callFunction(array $positional, ?BuiltinCallContext $context): AstNode
    {
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

        $registry = $context->registry;
        $result   = $registry->tryCall($name, array_slice($positional, 1), $context);

        if ($result === null) {
            $capturedScope = $positional[0] instanceof FunctionNode ? $positional[0]->capturedScope : null;

            return new FunctionNode($name, array_slice($positional, 1), capturedScope: $capturedScope);
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
            NameNormalizer::normalize($positional[0]->value),
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
        $module = $this->optionalModuleArgument($positional, $named);
        $scope  = $this->scopeFromContext($context);

        if ($module !== null) {
            $moduleScope           = $scope->getModule($module);
            $hasBuiltinModuleAlias = $context?->registry?->resolveModuleAlias($module) !== null;

            if (! $hasBuiltinModuleAlias && $moduleScope === null) {
                throw ModuleResolutionException::unknownNamespace($module);
            }

            if ($context?->registry?->hasFunction($name, $module) === true) {
                return $this->boolNode(true);
            }

            return $this->boolNode($moduleScope?->hasFunction($name) ?? false);
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
        $name   = $this->requiredString($positional, 'meta.get-function');
        $module = $this->optionalModuleName($named['module'] ?? null);
        $scope  = $this->scopeFromContext($context);

        if ($module !== null) {
            $hasBuiltin = $context?->registry?->hasFunction($name, $module) === true;
            $hasUser    = $scope->getModule($module)?->hasFunction($name) ?? false;

            if (! $hasBuiltin && ! $hasUser) {
                throw ModuleResolutionException::callableNotFound(
                    $this->builtinErrorContext('meta.get-function'),
                    $name,
                    $module,
                );
            }

            $reference = new SassFunctionRef($module . '.' . $name);

            return new StringNode($reference->name());
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
            return new FunctionNode($name, capturedScope: $scope);
        }

        $reference = new SassFunctionRef($name);

        return new StringNode($reference->name());
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function getMixin(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $name   = $this->requiredString($positional, 'meta.get-mixin');
        $module = $this->optionalModuleName($named['module'] ?? null);
        $scope  = $this->scopeFromContext($context);

        if ($module !== null) {
            $moduleScope = $scope->getModule($module);

            if ($moduleScope === null || ! $moduleScope->hasMixin($name)) {
                throw ModuleResolutionException::callableNotFound(
                    $this->builtinErrorContext('meta.get-mixin'),
                    $name,
                    $module,
                );
            }

            return new MixinRefNode($module . '.' . $name);
        }

        if (! $scope->hasMixin($name)) {
            throw ModuleResolutionException::callableNotFound(
                $this->builtinErrorContext('meta.get-mixin'),
                $name,
            );
        }

        return new MixinRefNode($name);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function globalVariableExists(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedMetaFunction($context, 'global-variable-exists', $positional);

        $name   = $this->requiredString($positional, 'meta.global-variable-exists');
        $module = $this->optionalModuleArgument($positional, $named);
        $scope  = $this->scopeFromContext($context);

        if ($module !== null) {
            $moduleScope = $this->requireModuleScope($scope, $module);

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

        return new StringNode($this->formatValue($positional[0]));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function keywords(array $positional): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('meta.keywords'),
                'an argument list value',
            );
        }

        $value = $positional[0];

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
        $module = $this->optionalModuleArgument($positional, $named);
        $scope  = $this->scopeFromContext($context);

        if ($module !== null) {
            $moduleScope = $this->requireModuleScope($scope, $module);

            return $this->boolNode($moduleScope->hasMixin($name));
        }

        return $this->boolNode($this->mixinIsVisibleAtCallSite($scope, $name, $context));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function moduleFunctions(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $module = $this->requiredString($positional, 'meta.module-functions');
        $scope  = $this->scopeFromContext($context);

        if ($context !== null && $context->registry !== null) {
            $registry         = $context->registry;
            $builtinFunctions = $registry->moduleFunctionsByAlias($module);

            if ($builtinFunctions !== null) {
                $pairs = [];

                foreach ($builtinFunctions as $function) {
                    $pairs[] = new MapPair(
                        new StringNode($function),
                        new FunctionNode($module . '.' . $function, capturedScope: $scope),
                    );
                }

                return new MapNode($pairs);
            }
        }

        $scope = $scope->getModule($module);

        if ($scope === null) {
            throw ModuleResolutionException::unknownNamespace($module);
        }

        $pairs = [];

        foreach ($scope->getFunctions() as $name => $_function) {
            $pairs[] = new MapPair(
                new StringNode($name),
                new FunctionNode($module . '.' . $name, capturedScope: $scope),
            );
        }

        return new MapNode($pairs);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function moduleMixins(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $module = $this->requiredString($positional, 'meta.module-mixins');
        $scope  = $this->scopeFromContext($context)->getModule($module);

        if ($scope === null) {
            throw ModuleResolutionException::unknownNamespace($module);
        }

        $pairs = [];

        foreach ($scope->getMixins() as $name => $_mixin) {
            $pairs[] = new MapPair(new StringNode($name), new MixinRefNode($module . '.' . $name));
        }

        return new MapNode($pairs);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function moduleVariables(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $module = $this->requiredString($positional, 'meta.module-variables');
        $scope  = $this->scopeFromContext($context)->getModule($module);

        if ($scope === null) {
            throw ModuleResolutionException::unknownNamespace($module);
        }

        $pairs = [];

        foreach ($scope->getVariables() as $name => $value) {
            if (! $value instanceof AstNode) {
                continue;
            }

            $pairs[] = new MapPair(new StringNode($name), $value);
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

        return new StringNode($this->astType($positional[0]));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function variableExists(array $positional, ?BuiltinCallContext $context, array $named = []): AstNode
    {
        $this->warnAboutDeprecatedMetaFunction($context, 'variable-exists', $positional);

        $name   = $this->requiredString($positional, 'meta.variable-exists');
        $module = $this->optionalModuleArgument($positional, $named);
        $scope  = $this->scopeFromContext($context);

        if ($module !== null) {
            $moduleScope = $this->requireModuleScope($scope, $module);

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

    private function optionalModuleName(?AstNode $moduleNode): ?string
    {
        if ($moduleNode === null) {
            return null;
        }

        if (! ($moduleNode instanceof StringNode)) {
            throw new InvalidArgumentTypeException(
                'meta module argument',
                'string',
                get_debug_type($moduleNode),
            );
        }

        return $moduleNode->value;
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function optionalModuleArgument(array $positional, array $named): ?string
    {
        return $this->optionalModuleName($named['module'] ?? ($positional[1] ?? null));
    }

    private function requireModuleScope(Scope $scope, string $module): Scope
    {
        $moduleScope = $scope->getModule($module);

        if ($moduleScope === null) {
            throw ModuleResolutionException::unknownNamespace($module);
        }

        return $moduleScope;
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
}
