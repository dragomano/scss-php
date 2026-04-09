<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

final class ExtendsState
{
    /** @var array<string, array<int, string>> */
    public array $extendMap = [];

    /** @var array<int, array{target: string, source: string, context: string}> */
    public array $pendingExtends = [];

    /** @var array<string, array<string, true>> */
    public array $selectorContexts = [];

    public function reset(): void
    {
        $this->extendMap        = [];
        $this->pendingExtends   = [];
        $this->selectorContexts = [];
    }
}
