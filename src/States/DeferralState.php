<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Output\DeferredAtRuleChunk;
use Bugo\SCSS\Output\OutputChunk;

final class DeferralState
{
    /** @var array<int, list<OutputChunk>> */
    public array $atRootStack = [];

    /** @var array<int, list<OutputChunk>> */
    public array $bubblingStack = [];

    /** @var array<int, list<DeferredAtRuleChunk>> */
    public array $atRuleStack = [];

    public bool $currentRuleHasOutput = false;

    public function reset(): void
    {
        $this->atRootStack          = [];
        $this->bubblingStack        = [];
        $this->atRuleStack          = [];
        $this->currentRuleHasOutput = false;
    }
}
