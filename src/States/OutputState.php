<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Utils\SourceMapMapping;

final class OutputState
{
    /** @var array<string, array<int, string>> */
    public array $extendMap = [];

    /** @var array<int, array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string>> */
    public array $deferredAtRootStack = [];

    /** @var array<int, array<int, array{
     *     chunk:string,
     *     baseLine:int,
     *     baseColumn:int,
     *     mappings:array<int, SourceMapMapping>
     * }|string>> */
    public array $deferredBubblingStack = [];

    /** @var array<int, array<int, array{levels: int, chunk: string}>> */
    public array $deferredAtRuleStack = [];

    /** @var array<string, array<string, true>> */
    public array $selectorContexts = [];

    /** @var array<int, array{target: string, source: string, context: string}> */
    public array $pendingExtends = [];

    public function reset(): void
    {
        $this->extendMap             = [];
        $this->deferredAtRootStack   = [];
        $this->deferredBubblingStack = [];
        $this->deferredAtRuleStack   = [];
        $this->selectorContexts      = [];
        $this->pendingExtends        = [];
    }
}
