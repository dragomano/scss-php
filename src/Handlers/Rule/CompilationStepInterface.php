<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

interface CompilationStepInterface
{
    public function execute(RuleCompilationContext $ruleCtx): ?string;
}
