<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers;

use Bugo\SCSS\Handlers\Block\DeferredChunkManager;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Utils\RawChunk;

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
        private DeferredChunkManager $chunks,
    ) {}

    public function handleForward(ForwardNode $node, TraversalContext $ctx): string
    {
        $forwardKey  = $this->module->handleForward($node, $ctx->env);
        $moduleState = $this->module->state();

        if (isset($moduleState->emittedForwardCss[$forwardKey])) {
            return '';
        }

        $moduleState->emittedForwardCss[$forwardKey] = true;

        $css = $moduleState->forwardedModules[$forwardKey]['css'];

        if ($css !== '') {
            $namespace = $this->module->deriveNamespaceFromUsePath($node->path);
            $loaded    = $moduleState->getByNamespace($namespace);

            if ($loaded !== null) {
                $moduleState->emittedModuleCss[$loaded->id] = true;
            }
        }

        return $this->qualifyCssWithinParentSelector(
            $css,
            $this->selector->getCurrentParentSelector($ctx->env),
        ) ?? '';
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

                if ($outputState->hoistCssImports && $ctx->indent === 0) {
                    $outputState->cssImports[] = $line;

                    continue;
                }

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

            $parentSelector = $this->selector->getCurrentParentSelector($ctx->env);
            $inlined        = null;

            if ($parentSelector !== null && $parentSelector !== '') {
                $inlined = $this->module->inlineImportedFile(
                    $path,
                    fn(array $children): string => $this->chunks->compileBodyChunks(
                        $children,
                        $ctx,
                        $ctx->env->getCurrentScope(),
                    ),
                );
            }

            if ($inlined !== null) {
                if ($inlined === '') {
                    continue;
                }

                if ($output !== '' && ! $endsWithNewline) {
                    $output .= "\n";
                }

                $output .= $inlined;

                $inlinedLength   = strlen($inlined);
                $endsWithNewline = $inlined[$inlinedLength - 1] === "\n";

                continue;
            }

            $data = $this->module->loadAndEvaluateModule(
                $path,
                [],
                true,
                true,
                $this->module->extractAstVariables($ctx->env->getCurrentScope()->getVariables()),
            );

            $this->module->mergeScopeExports($data['scope'], $ctx->env->getCurrentScope(), trackImportedVariables: true);

            $css = $data['css'];

            if ($css === '') {
                continue;
            }

            $qualified = $this->qualifyCssWithinParentSelector($css, $parentSelector);

            if ($qualified === null) {
                continue;
            }

            $css = $qualified;

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

    public function handleUse(UseNode $node, TraversalContext $ctx): string
    {
        $this->module->handleUse($node, $ctx->env);

        if (str_starts_with($node->path, 'sass:')) {
            return '';
        }

        $namespace = $node->namespace ?? $this->module->deriveNamespaceFromUsePath($node->path);

        if ($namespace === '*') {
            return '';
        }

        $moduleState = $this->module->state();

        $loaded = $moduleState->getByNamespace($namespace);

        if ($loaded === null) {
            return '';
        }

        if (isset($moduleState->emittedUseCss[$loaded->id]) || isset($moduleState->emittedModuleCss[$loaded->id])) {
            return '';
        }

        $moduleState->emittedUseCss[$loaded->id] = true;
        $moduleState->emittedModuleCss[$loaded->id] = true;

        return $loaded->css;
    }

    private function qualifyCssWithinParentSelector(string $css, ?string $parentSelector): ?string
    {
        if ($css === '' || $parentSelector === null || $parentSelector === '') {
            return $css;
        }

        $qualifiedCss = $this->module->qualifyImportedCssWithParentSelector($css, $parentSelector);
        $stackIndex   = count($this->render->outputState()->deferral->atRootStack) - 1;

        if ($stackIndex < 0) {
            return $qualifiedCss;
        }

        $this->render->outputState()->deferral->atRootStack[$stackIndex][] = new RawChunk(
            $this->render->trimTrailingNewlines($qualifiedCss),
        );

        return null;
    }
}
