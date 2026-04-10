<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Runtime\Scope;

final class ModuleState
{
    /** @var array<string, LoadedModule> */
    private array $loadedModules = [];

    /** @var array<string, string> */
    private array $idToNamespace = [];

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

    /** @var array<string, bool> */
    public array $loadingFiles = [];

    public function registerModule(string $namespace, string $id, Scope $scope, string $css): void
    {
        $module = new LoadedModule($id, $scope, $css);
        $this->loadedModules[$namespace] = $module;
        $this->idToNamespace[$id]        = $namespace;
    }

    public function getByNamespace(string $namespace): ?LoadedModule
    {
        return $this->loadedModules[$namespace] ?? null;
    }

    public function getById(string $id): ?LoadedModule
    {
        $namespace = $this->idToNamespace[$id] ?? null;

        return $namespace !== null ? ($this->loadedModules[$namespace] ?? null) : null;
    }

    public function hasNamespace(string $namespace): bool
    {
        return isset($this->loadedModules[$namespace]);
    }

    public function addByNamespace(string $namespace, LoadedModule $module): void
    {
        $this->loadedModules[$namespace]  = $module;
        $this->idToNamespace[$module->id] = $namespace;
    }

    public function reset(): void
    {
        $this->loadedModules         = [];
        $this->idToNamespace         = [];
        $this->importedModules       = [];
        $this->forwardedModules      = [];
        $this->emittedForwardCss     = [];
        $this->emittedUseCss         = [];
        $this->importEvaluationDepth = 0;
        $this->callDepth             = 0;
        $this->loadingFiles          = [];
        $this->hasUseDirective       = false;
    }
}
