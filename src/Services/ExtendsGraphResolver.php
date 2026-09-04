<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\LoaderInterface;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Syntax;
use Throwable;

use function array_reverse;
use function str_contains;
use function str_starts_with;

/**
 * @phpstan-import-type BoxMeta from ExtendsResolver
 * @phpstan-import-type ExtensionStore from ExtendsResolver
 * @phpstan-type ModuleGraph array{
 *     post: list<string>,
 *     seen: array<string, true>,
 *     upstream: array<string, array<string, true>>,
 *     asts: array<string, RootNode>,
 *     stores: array<string, ExtensionStore>,
 *     metas: array<string, BoxMeta>,
 *     contexts: array<string, array<string, array<string, true>>>,
 *     breaks: array<string, array<string, bool>>,
 *     hasExtend: bool
 * }
 * @phpstan-type CollectionSnapshot array{
 *     events: array<int, array{
 *         type: 'rule',
 *         boxId: int,
 *         rawParts: array<int, string>,
 *         resolvedParts: array<int, string>,
 *         context: string
 *     }|array{
 *         type: 'extend',
 *         boxId: int,
 *         target: string,
 *         context: string,
 *         optional: bool,
 *         priority: int
 *     }>,
 *     pendingExtends: array<int, array{target: string, source: string, context: string, optional: bool, priority: int}>,
 *     selectorContexts: array<string, array<string, true>>,
 *     partLineBreaks: array<string, bool>,
 *     ruleCount: int,
 *     extendSequence: int
 * }
 *
 * @psalm-import-type BoxMeta from ExtendsResolver
 * @psalm-import-type ExtensionStore from ExtendsResolver
 */
final readonly class ExtendsGraphResolver
{
    private const MAX_DEPTH = 20;

    private const ROOT_ID = '';

    public function __construct(
        private CompilerContext $ctx,
        private LoaderInterface $loader,
        private ParserInterface $parser,
        private ExtendsResolver $extends,
        private Module $module,
    ) {}

    public function finalize(RootNode $rootAst): void
    {
        $state    = $this->ctx->outputState->extends;
        $snapshot = $this->captureCollection();
        $graph    = $this->buildGraph($rootAst, $snapshot['events'] !== []);

        $this->restoreCollection($snapshot);

        if ($graph === null) {
            $this->extends->finalizeCollectedExtends();

            return;
        }

        $rootBuilt = $this->extends->buildExtensionStore();

        $stores                = $graph['stores'];
        $stores[self::ROOT_ID] = $rootBuilt['store'];

        $metas                = $graph['metas'];
        $metas[self::ROOT_ID] = $rootBuilt['meta'];

        $this->propagateExtensions(
            $stores,
            $graph['upstream'],
            array_reverse([...$graph['post'], self::ROOT_ID]),
        );

        foreach ($graph['asts'] as $moduleId => $_) {
            $materialized = $this->extends->materializeExtensionStore($stores[$moduleId], $metas[$moduleId]);

            $state->moduleScopes[$moduleId] = [
                'extendMap'        => $materialized['extendMap'],
                'selectorContexts' => $graph['contexts'][$moduleId],
                'partLineBreaks'   => $graph['breaks'][$moduleId],
                'boxes'            => $materialized['boxes'],
            ];
        }

        if ($snapshot['events'] === []) {
            $this->extends->finalizeCollectedExtends();

            return;
        }

        $this->extends->applyExtensionStore($stores[self::ROOT_ID], $metas[self::ROOT_ID]);
    }

    /**
     * @return ModuleGraph|null
     */
    private function buildGraph(RootNode $rootAst, bool $rootHasExtends): ?array
    {
        /** @var ModuleGraph $graph */
        $graph = [
            'post'      => [],
            'seen'      => [],
            'upstream'  => [],
            'asts'      => [],
            'stores'    => [],
            'metas'     => [],
            'contexts'  => [],
            'breaks'    => [],
            'hasExtend' => $rootHasExtends,
        ];

        try {
            $this->visitDependencies(self::ROOT_ID, $rootAst, 0, $graph);
        } catch (Throwable) {
            return null;
        }

        if ($graph['asts'] === [] || ! $graph['hasExtend']) {
            return null;
        }

        try {
            foreach ($graph['post'] as $moduleId) {
                $collected = $this->collectModuleStore($graph['asts'][$moduleId]);

                $graph['stores'][$moduleId]   = $collected['store'];
                $graph['metas'][$moduleId]    = $collected['meta'];
                $graph['contexts'][$moduleId] = $collected['contexts'];
                $graph['breaks'][$moduleId]   = $collected['breaks'];
            }
        } catch (Throwable) {
            return null;
        }

        return $graph;
    }

    /**
     * @param ModuleGraph $graph
     * @param-out ModuleGraph $graph
     */
    private function visitDependencies(string $moduleId, RootNode $ast, int $depth, array &$graph): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        foreach ($this->moduleDependencies($ast) as [$path, $fromImport]) {
            $file = $this->tryLoad($path, $fromImport);

            if ($file === null) {
                continue;
            }

            $dependency = $file['path'];

            if (! $fromImport) {
                $graph['upstream'][$moduleId][$dependency] = true;
            }

            if (isset($graph['seen'][$dependency])) {
                if (! $fromImport) {
                    $this->ctx->moduleState->prefetchModule(
                        $moduleId,
                        $path,
                        $dependency,
                        $file['content'],
                        $graph['asts'][$dependency],
                    );
                }

                continue;
            }

            $graph['seen'][$dependency] = true;

            $syntax = Syntax::fromPath($dependency, $file['content']);

            $dependencyAst = $this->parser->parse(
                $this->ctx->normalizerPipeline->process($file['content'], $syntax),
            );

            $graph['asts'][$dependency] = $dependencyAst;

            if (! $fromImport) {
                $this->ctx->moduleState->prefetchModule(
                    $moduleId,
                    $path,
                    $dependency,
                    $file['content'],
                    $dependencyAst,
                );
            }

            if (str_contains($file['content'], '@extend')) {
                $graph['hasExtend'] = true;
            }

            $this->visitDependencies($dependency, $dependencyAst, $depth + 1, $graph);

            $graph['post'][] = $dependency;
        }
    }

    /**
     * @return array{path: string, content: string}|null
     */
    private function tryLoad(string $path, bool $fromImport): ?array
    {
        try {
            return $this->loader->load($path, $fromImport);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return list<array{0: string, 1: bool}>
     */
    private function moduleDependencies(RootNode $ast): array
    {
        $paths = [];
        $seen  = [];

        foreach ($ast->children as $child) {
            if ($child instanceof ImportNode) {
                foreach ($child->imports as $import) {
                    $resolved = $this->module->resolveImport($import);

                    if ($resolved['type'] !== 'sass') {
                        continue;
                    }

                    /** @var array{type: 'sass', path: string} $resolved */
                    $importedPath = $resolved['path'];

                    if (isset($seen[$importedPath])) {
                        continue;
                    }

                    $seen[$importedPath] = true;
                    $paths[]             = [$importedPath, true];
                }

                continue;
            }

            if (! $child instanceof UseNode && ! $child instanceof ForwardNode) {
                continue;
            }

            if (str_starts_with($child->path, 'sass:') || isset($seen[$child->path])) {
                continue;
            }

            $seen[$child->path] = true;
            $paths[]            = [$child->path, false];
        }

        return $paths;
    }

    /**
     * @return array{
     *     store: ExtensionStore,
     *     meta: BoxMeta,
     *     contexts: array<string, array<string, true>>,
     *     breaks: array<string, bool>
     * }
     */
    private function collectModuleStore(RootNode $ast): array
    {
        $state = $this->ctx->outputState->extends;

        $state->resetCollection();

        $this->extends->collectExtends($ast, new Environment());

        $built = $this->extends->buildExtensionStore();

        return [
            'store'    => $built['store'],
            'meta'     => $built['meta'],
            'contexts' => $state->selectorContexts,
            'breaks'   => $state->partLineBreaks,
        ];
    }

    /**
     * @param array<string, ExtensionStore> $stores
     * @param-out array<string, ExtensionStore> $stores
     * @param array<string, array<string, true>> $upstream
     * @param list<string> $order
     */
    private function propagateExtensions(array &$stores, array $upstream, array $order): void
    {
        /** @var array<string, list<ExtensionStore>> $downstream */
        $downstream = [];

        foreach ($order as $moduleId) {
            if (! isset($stores[$moduleId])) {
                continue;
            }

            if (isset($downstream[$moduleId])) {
                $this->extends->addForeignExtensionsToStore($stores[$moduleId], $downstream[$moduleId]);
            }

            if ($stores[$moduleId]['extensions'] === []) {
                continue;
            }

            foreach ($upstream[$moduleId] ?? [] as $dependency => $_) {
                $downstream[$dependency][] = $stores[$moduleId];
            }
        }
    }

    /**
     * @return CollectionSnapshot
     */
    private function captureCollection(): array
    {
        $state = $this->ctx->outputState->extends;

        return [
            'events'           => $state->events,
            'pendingExtends'   => $state->pendingExtends,
            'selectorContexts' => $state->selectorContexts,
            'partLineBreaks'   => $state->partLineBreaks,
            'ruleCount'        => $state->ruleCount,
            'extendSequence'   => $state->extendSequence,
        ];
    }

    /**
     * @param CollectionSnapshot $snapshot
     */
    private function restoreCollection(array $snapshot): void
    {
        $state = $this->ctx->outputState->extends;

        $state->resetCollection();

        $state->events           = $snapshot['events'];
        $state->pendingExtends   = $snapshot['pendingExtends'];
        $state->selectorContexts = $snapshot['selectorContexts'];
        $state->partLineBreaks   = $snapshot['partLineBreaks'];
        $state->ruleCount        = $snapshot['ruleCount'];
        $state->extendSequence   = $snapshot['extendSequence'];
    }
}
