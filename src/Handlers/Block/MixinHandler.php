<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Block;

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\NameHelper;
use Bugo\SCSS\Utils\RawChunk;

use function array_slice;
use function str_contains;
use function str_starts_with;

final readonly class MixinHandler
{
    public function __construct(
        private Evaluator $evaluation,
        private FunctionRegistry $registry,
        private Module $module,
        private Selector $selector,
        private DeferredChunkManager $chunks,
    ) {}

    public function handleInclude(IncludeNode $node, TraversalContext $ctx): string
    {
        if ($this->isMetaApply($node)) {
            return $this->handleMetaApply($node, $ctx);
        }

        if ($this->isMetaLoadCss($node)) {
            return $this->handleMetaLoadCss($node, $ctx);
        }

        [$mixin, $moduleScopeForInclude] = $this->resolveMixin(
            $node->namespace,
            $node->name,
            $ctx->env->getCurrentScope(),
        );

        if ($mixin === null) {
            throw UndefinedSymbolException::mixin($node->name);
        }

        [$resolvedPositional, $resolvedNamed] = $this->evaluation->resolveCallArguments($node->arguments, $ctx->env);

        return $this->compileMixin(
            $mixin,
            $moduleScopeForInclude,
            $resolvedPositional,
            $resolvedNamed,
            $node->contentBlock,
            $node->contentArguments,
            $node->hasContent,
            $ctx,
        );
    }

    private function isMetaApply(IncludeNode $node): bool
    {
        return $node->name === 'apply'
            && $node->namespace !== null
            && $this->registry->resolveModuleAlias($node->namespace) === 'meta';
    }

    private function isMetaLoadCss(IncludeNode $node): bool
    {
        return $node->name === 'load-css'
            && $node->namespace !== null
            && $this->registry->resolveModuleAlias($node->namespace) === 'meta';
    }

    private function handleMetaApply(IncludeNode $node, TraversalContext $ctx): string
    {
        [$resolvedPositional, $resolvedNamed] = $this->evaluation->resolveCallArguments($node->arguments, $ctx->env);

        $first = $resolvedPositional[0] ?? $resolvedNamed['mixin'] ?? null;

        if ((! ($first instanceof StringNode) && ! ($first instanceof MixinRefNode))) {
            return '';
        }

        $mixinName = $first instanceof MixinRefNode ? $first->name : $first->value;

        if ($first instanceof MixinRefNode && $first->lockedDefinition !== null) {
            $mixin = $first->lockedDefinition;
            $moduleScopeForInclude = null;
        } else {
            [$namespace, $name] = $this->parseMixinReference($mixinName);

            [$mixin, $moduleScopeForInclude] = $this->resolveMixin($namespace, $name, $ctx->env->getCurrentScope());
        }

        if ($mixin === null) {
            return '';
        }

        $restPositional = $first instanceof MixinRefNode
            ? array_slice($resolvedPositional, 1)
            : $resolvedPositional;

        $restNamed = $resolvedNamed;
        unset($restNamed['mixin']);

        return $this->compileMixin(
            $mixin,
            $moduleScopeForInclude,
            $restPositional,
            $restNamed,
            $node->contentBlock,
            $node->contentArguments,
            $node->hasContent,
            $ctx,
        );
    }

    private function handleMetaLoadCss(IncludeNode $node, TraversalContext $ctx): string
    {
        [$resolvedPositional, $resolvedNamed] = $this->evaluation->resolveCallArguments($node->arguments, $ctx->env);

        $urlNode = $resolvedPositional[0] ?? $resolvedNamed['url'] ?? null;

        if (! ($urlNode instanceof StringNode)) {
            return '';
        }

        $configuration = $this->metaLoadCssConfiguration($resolvedNamed['with'] ?? null);

        $path = $urlNode->value;

        if (! str_starts_with($path, 'sass:')) {
            $resolvedPath = $this->module->resolveModulePath($path);

            if ($resolvedPath !== null) {
                $path = $resolvedPath;
            }
        }

        $moduleState = $this->module->state();

        $previousImportRoot             = $moduleState->currentImportRoot;
        $moduleState->currentImportRoot = $path;

        try {
            $result = $this->module->loadAndEvaluateModule(
                $path,
                $configuration,
            );
        } finally {
            $moduleState->currentImportRoot = $previousImportRoot;
        }

        $css = $result['css'];

        if ($configuration !== []) {
            $state     = $this->module->state();
            $namespace = $this->module->deriveNamespaceFromUsePath($urlNode->value);

            if (! $state->hasNamespace($namespace)) {
                $state->registerModule($namespace, $path, $result['scope'], $css);
            }
        }

        if ($css === '') {
            return '';
        }

        $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);

        if ($parentSelector !== null && $parentSelector !== '') {
            $qualifiedCss = $this->module->qualifyImportedCssWithParentSelector($css, $parentSelector);

            if ($this->chunks->appendDeferredBubblingChunk(new RawChunk($qualifiedCss))) {
                return '';
            }

            return $qualifiedCss;
        }

        return $css;
    }

    /**
     * @param array<int, AstNode> $resolvedPositional
     * @param array<string, AstNode> $resolvedNamed
     * @param array<int, AstNode> $contentBlock
     * @param array<int, ArgumentNode> $contentArguments
     */
    private function compileMixin(
        CallableDefinition $mixin,
        ?Scope $moduleScopeForInclude,
        array $resolvedPositional,
        array $resolvedNamed,
        array $contentBlock,
        array $contentArguments,
        bool $hasContent,
        TraversalContext $ctx,
    ): string {
        $this->module->incrementCallDepth();

        $output = '';

        $includeCallScope = $ctx->env->getCurrentScope();

        $ctx->env->enterScope($mixin->closureScope);

        $childCtx = new TraversalContext($ctx->env, $ctx->indent);

        try {
            $executionScope = $ctx->env->getCurrentScope();

            if ($moduleScopeForInclude instanceof Scope) {
                $executionScope->setVariableLocal('__module_global_target', $moduleScopeForInclude);
            }

            $parentSelector = $includeCallScope->getStringVariable('__parent_selector');

            if ($parentSelector !== null) {
                $executionScope->setVariableLocal('__parent_selector', $parentSelector);
            }

            $atRootContext = $includeCallScope->getAstVariable('__at_root_context');

            if ($atRootContext !== null) {
                $executionScope->setVariableLocal('__at_root_context', $atRootContext);
            }

            $executionScope->setVariableLocal(
                '__meta_content_exists',
                $this->evaluation->createBooleanNode($hasContent),
            );

            $executionScope->setVariableLocal('__meta_content_block', $contentBlock);
            $executionScope->setVariableLocal('__meta_content_arguments', $contentArguments);
            $executionScope->setVariableLocal('__meta_content_scope', $includeCallScope);

            $this->evaluation->bindParametersToCurrentScope(
                $mixin->arguments,
                $resolvedPositional,
                $resolvedNamed,
                $executionScope,
                $ctx->env,
            );

            $output = $this->chunks->compileBodyChunks($mixin->body, $childCtx, $includeCallScope);
        } finally {
            $ctx->env->exitScope();

            $this->module->decrementCallDepth();
        }

        return $output;
    }

    /**
     * @return array{0: CallableDefinition|null, 1: Scope|null}
     */
    private function resolveMixin(?string $namespace, string $name, Scope $scope): array
    {
        if ($namespace === null || $namespace === '') {
            $scopedMixin = $scope->findMixin($name);

            if ($scopedMixin !== null) {
                return [$scopedMixin->definition, null];
            }

            return [null, null];
        }

        $moduleScope = $scope->getModule($namespace);

        if ($moduleScope !== null) {
            $scopedMixin = $moduleScope->findMixin($name);

            if ($scopedMixin !== null) {
                return [$scopedMixin->definition, $moduleScope];
            }
        }

        return [null, null];
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function parseMixinReference(string $reference): array
    {
        if (! str_contains($reference, '.')) {
            return [null, $reference];
        }

        $parts = NameHelper::splitQualifiedName($reference);

        return [$parts['namespace'], $parts['member'] ?? ''];
    }

    /**
     * @return array<string, AstNode>
     */
    private function metaLoadCssConfiguration(?AstNode $value): array
    {
        if (! $value instanceof MapNode) {
            return [];
        }

        $configuration = [];

        foreach ($value->pairs as $pair) {
            if (! ($pair->key instanceof StringNode)) {
                continue;
            }

            $configuration[$pair->key->value] = $pair->value;
        }

        return $configuration;
    }
}
