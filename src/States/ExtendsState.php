<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

final class ExtendsState
{
    /** @var array<string, array<int, string>> */
    public array $extendMap = [];

    /** @var array<int, array{target: string, source: string, context: string, optional: bool, priority: int}> */
    public array $pendingExtends = [];

    /** @var array<string, array<string, true>> */
    public array $selectorContexts = [];

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

    /** @var array<int, array<string, mixed>> */
    public array $boxes = [];

    public function reset(): void
    {
        $this->extendMap        = [];
        $this->pendingExtends   = [];
        $this->selectorContexts = [];
        $this->events           = [];
        $this->ruleCount        = 0;
        $this->ruleStack        = [];
        $this->extendSequence   = 0;
        $this->boxes            = [];
    }
}
