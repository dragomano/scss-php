<?php

declare(strict_types=1);

namespace DartSass\Handlers\Builtins;

interface QuotedStringArgumentsInterface extends ModuleHandlerInterface
{
    public function shouldPreserveQuotedStringArguments(string $functionName): bool;
}
