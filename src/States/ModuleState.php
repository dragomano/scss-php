<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Runtime\Scope;

final class ModuleState
{
    /** @var array<string, array{id: string, scope: Scope, css: string}> */
    public array $loadedModules = [];

    /** @var array<string, array{id: string, scope: Scope, css: string}> */
    public array $loadedModulesById = [];

    /** @var array<string, mixed> */
    public array $importedModules = [];

    /** @var array<string, array{id?: string, scope: Scope, css: string}> */
    public array $forwardedModules = [];

    /** @var array<string, bool> */
    public array $emittedForwardCss = [];

    /** @var array<string, bool> */
    public array $emittedUseCss = [];

    public int $importEvaluationDepth = 0;

    public int $callDepth = 0;

    public bool $hasUseDirective = false;

    public bool $hasSassImport = false;

    /** @var array<string, bool> */
    public array $loadingFiles = [];

    public function reset(): void
    {
        $this->loadedModules         = [];
        $this->loadedModulesById     = [];
        $this->importedModules       = [];
        $this->forwardedModules      = [];
        $this->emittedForwardCss     = [];
        $this->emittedUseCss         = [];
        $this->importEvaluationDepth = 0;
        $this->callDepth             = 0;
        $this->loadingFiles          = [];
        $this->hasUseDirective       = false;
        $this->hasSassImport         = false;
    }
}
