<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Block;

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\NameHelper;

use function array_slice;
use function str_contains;

final readonly class MixinHandler
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Evaluator $evaluation,
        private FunctionRegistry $registry,
        private Module $module,
        private Render $render,
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

        $first = $resolvedPositional[0] ?? null;

        if ((! ($first instanceof StringNode) && ! ($first instanceof MixinRefNode))) {
            return '';
        }

        $mixinName = $first instanceof MixinRefNode ? $first->name : $first->value;

        [$namespace, $name] = $this->parseMixinReference($mixinName);

        [$mixin, $moduleScopeForInclude] = $this->resolveMixin($namespace, $name, $ctx->env->getCurrentScope());

        if ($mixin === null) {
            return '';
        }

        return $this->compileMixin(
            $mixin,
            $moduleScopeForInclude,
            array_slice($resolvedPositional, 1),
            $resolvedNamed,
            $node->contentBlock,
            $node->contentArguments,
            $ctx,
        );
    }

    private function handleMetaLoadCss(IncludeNode $node, TraversalContext $ctx): string
    {
        [$resolvedPositional, $resolvedNamed] = $this->evaluation->resolveCallArguments($node->arguments, $ctx->env);

        if ($resolvedPositional === [] || ! ($resolvedPositional[0] instanceof StringNode)) {
            return '';
        }

        $css = $this->module->loadAndEvaluateModule(
            $resolvedPositional[0]->value,
            $this->metaLoadCssConfiguration($resolvedNamed['with'] ?? null),
        )['css'];

        if ($css === '') {
            return '';
        }

        $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);

        if ($parentSelector !== null && $parentSelector !== '') {
            $qualifiedCss = $this->module->qualifyImportedCssWithParentSelector($css, $parentSelector);

            if ($this->chunks->appendDeferredBubblingChunk($qualifiedCss)) {
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
                $this->evaluation->createBooleanNode($contentBlock !== []),
            );

            $executionScope->setVariableLocal('__meta_content_block', $contentBlock);
            $executionScope->setVariableLocal('__meta_content_arguments', $contentArguments);
            $executionScope->setVariableLocal('__meta_content_scope', $includeCallScope);

            $this->evaluation->bindParametersToCurrentScope(
                $mixin->arguments,
                $resolvedPositional,
                $resolvedNamed,
                $executionScope,
            );

            $first = true;

            foreach ($mixin->body as $child) {
                if ($this->evaluation->applyVariableDeclaration($child, $ctx->env)) {
                    continue;
                }

                if ($child instanceof AtRootNode) {
                    $this->chunks->appendIncludeAtRootChunk($output, $first, $child, $ctx);

                    continue;
                }

                if ($this->evaluation->isBubblingAtRuleNode($child)) {
                    $this->chunks->appendIncludeBubblingChunk($output, $first, $child, $ctx);

                    continue;
                }

                if ($child instanceof RuleNode) {
                    $this->chunks->appendIncludedRuleChunk($output, $first, $child, $ctx);

                    continue;
                }

                $savedPosition = null;

                if ($this->render->collectSourceMappings() && ! $child instanceof DeclarationNode) {
                    $savedPosition = $this->render->savePosition();
                }

                /** @var Visitable $child */
                $compiled = $this->dispatcher->compileWithContext($child, $childCtx);

                if ($compiled === '') {
                    continue;
                }

                if ($child instanceof DeclarationNode) {
                    if (! $first) {
                        $this->render->appendChunk($output, "\n");
                    }

                    $this->render->appendChunk($output, $compiled, $child);
                } else {
                    $compiled = $this->render->trimAndAdjustState($compiled);

                    if ($savedPosition !== null) {
                        $deferredChunk = $this->render->createDeferredChunk($compiled, $savedPosition);

                        $this->render->restorePosition($savedPosition);

                        if (! $first) {
                            $this->render->appendChunk($output, "\n");
                        }

                        $this->render->appendDeferredChunk($output, $deferredChunk);
                    } else {
                        if (! $first) {
                            $this->render->appendChunk($output, "\n");
                        }

                        $output .= $compiled;
                    }
                }

                $first = false;
            }
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
            if (! ($pair['key'] instanceof StringNode)) {
                continue;
            }

            $configuration[$pair['key']->value] = $pair['value'];
        }

        return $configuration;
    }
}
