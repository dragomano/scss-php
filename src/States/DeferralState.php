<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Runtime\DeferredAtRuleChunk;
use Bugo\SCSS\Utils\OutputChunk;

final class DeferralState
{
    /** @var array<int, list<OutputChunk>> */
    public array $atRootStack = [];

    /** @var array<int, list<OutputChunk>> */
    public array $bubblingStack = [];

    /** @var array<int, list<DeferredAtRuleChunk>> */
    public array $atRuleStack = [];

    public function reset(): void
    {
        $this->atRootStack   = [];
        $this->bubblingStack = [];
        $this->atRuleStack   = [];
    }
}
