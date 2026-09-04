<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Nodes\RootNode;
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

    /** @var array<string, bool> */
    public array $emittedModuleCss = [];

    public int $importEvaluationDepth = 0;

    public int $callDepth = 0;

    public bool $hasUseDirective = false;

    /** @var array<string, bool> */
    public array $loadingFiles = [];

    /** @var array<string, array{path: string, content: string}> */
    public array $prefetchedFiles = [];

    /** @var array<string, RootNode> */
    public array $prefetchedAsts = [];

    public string $currentModuleId = '';

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

    public function prefetchModule(string $parentId, string $url, string $path, string $content, RootNode $ast): void
    {
        $this->prefetchedFiles[$parentId . "\0" . $url] = ['path' => $path, 'content' => $content];
        $this->prefetchedAsts[$path]                    = $ast;
    }

    /**
     * @return array{path: string, content: string}|null
     */
    public function prefetchedFile(string $url): ?array
    {
        return $this->prefetchedFiles[$this->currentModuleId . "\0" . $url] ?? null;
    }

    public function prefetchedAst(string $path): ?RootNode
    {
        return $this->prefetchedAsts[$path] ?? null;
    }

    /**
     * @return array{forward: array<string, bool>, use: array<string, bool>, module: array<string, bool>}
     */
    public function takeEmittedCssState(): array
    {
        $snapshot = [
            'forward' => $this->emittedForwardCss,
            'use'     => $this->emittedUseCss,
            'module'  => $this->emittedModuleCss,
        ];

        $this->emittedForwardCss = [];
        $this->emittedUseCss     = [];
        $this->emittedModuleCss  = [];

        return $snapshot;
    }

    /**
     * @param array{forward: array<string, bool>, use: array<string, bool>, module: array<string, bool>} $snapshot
     */
    public function restoreEmittedCssState(array $snapshot): void
    {
        $this->emittedForwardCss = $snapshot['forward'];
        $this->emittedUseCss     = $snapshot['use'];
        $this->emittedModuleCss  = $snapshot['module'];
    }

    public function reset(): void
    {
        $this->loadedModules         = [];
        $this->idToNamespace         = [];
        $this->importedModules       = [];
        $this->forwardedModules      = [];
        $this->emittedForwardCss     = [];
        $this->emittedUseCss         = [];
        $this->emittedModuleCss      = [];
        $this->importEvaluationDepth = 0;
        $this->callDepth             = 0;
        $this->loadingFiles          = [];
        $this->hasUseDirective       = false;
        $this->prefetchedFiles       = [];
        $this->prefetchedAsts        = [];
        $this->currentModuleId       = '';
    }
}
