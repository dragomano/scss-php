<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

/**
 * @phpstan-type ExtendsBox array{
 *     rawParts: array<int, string>,
 *     selectors: array<int, string>,
 *     originals: array<int, string>,
 *     context: string
 * }
 * @phpstan-type ExtendsScope array{
 *     extendMap: array<string, array<int, array{source: string, priority: int}>>,
 *     selectorContexts: array<string, array<string, true>>,
 *     partLineBreaks: array<string, bool>,
 *     boxes: array<int, ExtendsBox>
 * }
 *
 * @psalm-type ExtendsBox=array{
 *     rawParts: array<int, string>,
 *     selectors: array<int, string>,
 *     originals: array<int, string>,
 *     context: string
 * }
 * @psalm-type ExtendsScope=array{
 *     extendMap: array<string, array<int, array{source: string, priority: int}>>,
 *     selectorContexts: array<string, array<string, true>>,
 *     partLineBreaks: array<string, bool>,
 *     boxes: array<int, ExtendsBox>
 * }
 */
final class ExtendsState
{
    /** @var array<string, array<int, array{source: string, priority: int}>> */
    public array $extendMap = [];

    /** @var array<int, array{target: string, source: string, context: string, optional: bool, priority: int}> */
    public array $pendingExtends = [];

    /** @var array<string, array<string, true>> */
    public array $selectorContexts = [];

    /** @var array<string, bool> */
    public array $partLineBreaks = [];

    /**
     * @var array<int, array{
     *     type: 'rule',
     *     boxId: int,
     *     rawParts: array<int, string>,
     *     resolvedParts: array<int, string>,
     *     context: string
     * }|array{
     *     type: 'extend',
     *     boxId: int,
     *     target: string,
     *     context: string,
     *     optional: bool,
     *     priority: int
     * }>
     */
    public array $events = [];

    public int $ruleCount = 0;

    /** @var array<int, int> */
    public array $ruleStack = [];

    public int $extendSequence = 0;

    /** @var array<int, ExtendsBox> */
    public array $boxes = [];

    /** @var array<string, ExtendsScope> */
    public array $moduleScopes = [];

    /** @var array<string, array<string, ExtendsScope>> */
    public array $moduleScopesImport = [];

    /**
     * @return ExtendsScope
     */
    public function captureScope(): array
    {
        return [
            'extendMap'        => $this->extendMap,
            'selectorContexts' => $this->selectorContexts,
            'partLineBreaks'   => $this->partLineBreaks,
            'boxes'            => $this->boxes,
        ];
    }

    /**
     * @param ExtendsScope $scope
     */
    public function applyScope(array $scope): void
    {
        $this->extendMap        = $scope['extendMap'];
        $this->selectorContexts = $scope['selectorContexts'];
        $this->partLineBreaks   = $scope['partLineBreaks'];
        $this->boxes            = $scope['boxes'];
    }

    /**
     * @return ExtendsScope|null
     */
    public function enterModuleScope(string $moduleId): ?array
    {
        if (! isset($this->moduleScopes[$moduleId])) {
            return null;
        }

        $previous = $this->captureScope();

        $this->applyScope($this->moduleScopes[$moduleId]);

        return $previous;
    }

    /**
     * @param ExtendsScope|null $previous
     */
    public function leaveModuleScope(?array $previous): void
    {
        if ($previous === null) {
            return;
        }

        $this->applyScope($previous);
    }

    /**
     * @return ExtendsScope|null
     */
    public function enterImportModuleScope(string $importRoot, string $moduleId): ?array
    {
        $scope = $this->moduleScopesImport[$importRoot][$moduleId]
            ?? $this->moduleScopes[$moduleId]
            ?? null;

        if ($scope === null) {
            return null;
        }

        $previous = $this->captureScope();

        $this->applyScope($scope);

        return $previous;
    }

    public function resetCollection(): void
    {
        $this->extendMap        = [];
        $this->pendingExtends   = [];
        $this->selectorContexts = [];
        $this->partLineBreaks   = [];
        $this->events           = [];
        $this->ruleCount        = 0;
        $this->ruleStack        = [];
        $this->extendSequence   = 0;
        $this->boxes            = [];
    }

    public function reset(): void
    {
        $this->resetCollection();

        $this->moduleScopes       = [];
        $this->moduleScopesImport = [];
    }
}
