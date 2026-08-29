<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

final class OutputState
{
    /** @var list<string> */
    public array $cssImports = [];

    public bool $hoistCssImports = false;

    public ExtendsState $extends;

    public DeferralState $deferral;

    public function __construct()
    {
        $this->extends  = new ExtendsState();
        $this->deferral = new DeferralState();
    }

    public function reset(): void
    {
        $this->cssImports      = [];
        $this->hoistCssImports = false;

        $this->extends->reset();
        $this->deferral->reset();
    }
}
