<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\SCSS\Runtime\BuiltinCallContext;
use Closure;

final readonly class ColorModuleContext
{
    /**
     * @param Closure(string): string $errorCtx
     * @param Closure(): bool $isGlobalBuiltinCall
     * @param Closure(?BuiltinCallContext, string, bool=): void $warn
     */
    public function __construct(
        private Closure $errorCtx,
        private Closure $isGlobalBuiltinCall,
        private Closure $warn,
    ) {}

    public function errorCtx(string $function): string
    {
        return ($this->errorCtx)($function);
    }

    public function isGlobalBuiltinCall(): bool
    {
        return ($this->isGlobalBuiltinCall)();
    }

    public function warn(?BuiltinCallContext $context, string $message, bool $multipleSuggestions = false): void
    {
        ($this->warn)($context, $message, $multipleSuggestions);
    }
}
