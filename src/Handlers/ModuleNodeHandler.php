<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;

use function count;
use function str_contains;
use function str_starts_with;
use function strlen;

final readonly class ModuleNodeHandler
{
    public function __construct(
        private Evaluator $evaluation,
        private Module $module,
        private Render $render,
        private Selector $selector,
    ) {}

    public function handleForward(ForwardNode $node, TraversalContext $ctx): string
    {
        $path                  = $node->path;
        $resolvedConfiguration = $this->module->resolveForwardConfiguration($node, $ctx->env);
        $moduleState           = $this->module->moduleState();

        if ($this->module->importEvaluationDepth() > 0) {
            $resolvedConfiguration = $this->module->resolveImportForwardConfiguration(
                $node,
                $ctx->env,
                $resolvedConfiguration,
            );
        }

        $forwardKey = $this->module->forwardCacheKey($path, $resolvedConfiguration, $ctx->env);

        if (! isset($moduleState->forwardedModules[$forwardKey])) {
            $moduleState->forwardedModules[$forwardKey] = $this->module->loadAndEvaluateModule(
                $path,
                $resolvedConfiguration,
            );
        }

        if (isset($moduleState->emittedForwardCss[$forwardKey])) {
            return '';
        }

        $moduleState->emittedForwardCss[$forwardKey] = true;

        return $moduleState->forwardedModules[$forwardKey]['css'];
    }

    public function handleImport(ImportNode $node, TraversalContext $ctx): string
    {
        $output          = '';
        $prefix          = $this->render->indentPrefix($ctx->indent);
        $endsWithNewline = false;
        $outputState     = $this->render->outputState();

        foreach ($node->imports as $import) {
            $resolvedImport = $this->module->resolveImport($import);

            if ($resolvedImport['type'] === 'css') {
                /** @var array{type: 'css', raw: string} $resolvedImport */
                $rawImport = $resolvedImport['raw'];

                if (str_contains($rawImport, '#{')) {
                    $rawImport = $this->evaluation->interpolateText($rawImport, $ctx->env);
                }

                $line = '@import ' . $rawImport . ';';

                if ($output !== '' && ! $endsWithNewline) {
                    $output .= "\n";
                }

                $output .= $prefix . $line;
                $endsWithNewline = false;

                continue;
            }

            /** @var array{type: 'sass', path: string} $resolvedImport */
            $path = $resolvedImport['path'];

            if ($path === '') {
                continue;
            }

            $data = $this->module->loadAndEvaluateModule(
                $path,
                [],
                true,
                true,
                $this->module->extractAstVariables($ctx->env->getCurrentScope()->getVariables()),
            );

            $css = $data['css'];

            if ($css === '') {
                continue;
            }

            $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);

            if ($parentSelector !== null && $parentSelector !== '') {
                $qualifiedCss = $this->module->qualifyImportedCssWithParentSelector($css, $parentSelector);
                $stackIndex   = count($outputState->deferredAtRootStack) - 1;

                if ($stackIndex >= 0) {
                    $outputState->deferredAtRootStack[$stackIndex][] = $this->render->trimTrailingNewlines(
                        $qualifiedCss,
                    );

                    continue;
                }

                $css = $qualifiedCss;
            }

            if ($output !== '' && ! $endsWithNewline) {
                $output .= "\n";
            }

            $indented = $this->render->indentLines($css, $prefix);
            $output  .= $indented;

            $indentedLength  = strlen($indented);
            $endsWithNewline = $indentedLength > 0 && $indented[$indentedLength - 1] === "\n";
        }

        return $output;
    }

    public function handleUse(UseNode $node): string
    {
        if (str_starts_with($node->path, 'sass:')) {
            return '';
        }

        $namespace = $node->namespace;

        if ($namespace === null) {
            $namespace = $this->module->deriveNamespaceFromUsePath($node->path);
        }

        if ($namespace === '*') {
            return '';
        }

        $moduleState = $this->module->moduleState();

        if (! isset($moduleState->loadedModules[$namespace])) {
            return '';
        }

        $moduleData = $moduleState->loadedModules[$namespace];
        $moduleId   = $moduleData['id'];

        if (isset($moduleState->emittedUseCss[$moduleId])) {
            return '';
        }

        $moduleState->emittedUseCss[$moduleId] = true;

        return $moduleData['css'];
    }
}
