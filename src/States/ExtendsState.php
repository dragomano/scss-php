<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

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

    /**
     * @var array<int, array{
     *     rawParts: array<int, string>,
     *     selectors: array<int, string>,
     *     originals: array<int, string>,
     *     context: string
     * }>
     */
    public array $boxes = [];

    public function reset(): void
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
}
