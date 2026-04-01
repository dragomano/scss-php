<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\LoaderInterface;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

describe('Module service', function () {
    beforeEach(function () {
        $this->loader = new class () implements LoaderInterface {
            public array $files = [];

            public array $paths = [];

            public function addPath(string $path): void
            {
                $this->paths[] = $path;
            }

            public function load(string $url, bool $fromImport = false): array
            {
                return $this->files[$url] ?? ['path' => $url, 'content' => ''];
            }
        };

        $this->parser = new class () implements ParserInterface {
            public function setTrackSourceLocations(bool $track): void {}

            public function parse(string $source): RootNode
            {
                return new RootNode([]);
            }
        };

        $this->runtime = RuntimeFactory::createRuntime(
            loader: $this->loader,
            parser: $this->parser
        );

        $this->module = $this->runtime->module();
        $this->ctx    = (new ReflectionAccessor($this->runtime))->getProperty('ctx');
    });

    it('assignModuleVariable() throws when module cannot be resolved', function () {
        $env = new Environment();

        expect(fn() => $this->module->assignModuleVariable(
            new ModuleVarDeclarationNode('missing', 'color', new StringNode('red')),
            $env
        ))->toThrow(ModuleResolutionException::class);
    });

    it('assignModuleVariable() throws for private module variables', function () {
        $env = new Environment();
        $env->getCurrentScope()->addModule('theme', new Scope());

        expect(fn() => $this->module->assignModuleVariable(
            new ModuleVarDeclarationNode('theme', '-secret', new StringNode('red')),
            $env
        ))->toThrow(UndefinedSymbolException::class);
    });

    it('incrementCallDepth() throws when include recursion limit is reached', function () {
        $this->ctx->moduleState->callDepth = 100;

        expect(fn() => $this->module->incrementCallDepth())
            ->toThrow(MaxIterationsExceededException::class);
    });

    it('handleUse() throws when @use is mixed with top-level sass imports', function () {
        $env = new Environment();

        $this->ctx->moduleState->hasSassImport = true;

        expect(fn() => $this->module->handleUse(new UseNode('module'), $env))
            ->toThrow(ModuleResolutionException::class);
    });

    it('handleUse() returns early for built-in wildcard namespaces', function () {
        $env = new Environment();

        $this->module->handleUse(new UseNode('sass:math', '*'), $env);

        expect($env->getCurrentScope()->hasModuleLocal('*'))->toBeFalse();
    });

    it('handleUse() throws on duplicate local namespaces', function () {
        $env = new Environment();
        $env->getCurrentScope()->addModule('theme', new Scope());

        expect(fn() => $this->module->handleUse(new UseNode('theme', 'theme'), $env))
            ->toThrow(ModuleResolutionException::class);
    });

    it('handleUse() throws when configuring a non-default module variable', function () {
        $env = new Environment();

        $this->loader->files['theme'] = ['path' => '/tmp/_theme.scss', 'content' => ''];

        expect(fn() => $this->module->handleUse(
            new UseNode('theme', 'theme', ['color' => new StringNode('red')]),
            $env
        ))->toThrow(
            ModuleResolutionException::class,
            "This variable isn't declared with !default in the target stylesheet, so it can't be configured: \$color (in module '/tmp/_theme.scss')."
        );
    });

    it('handleUse() reuses already loaded modules by file id', function () {
        $env   = new Environment();
        $scope = new Scope();

        $moduleData = ['id' => '/tmp/_theme.scss', 'scope' => $scope, 'css' => ''];

        $this->ctx->moduleState->loadedModulesById['/tmp/_theme.scss'] = $moduleData;
        $this->loader->files['theme'] = ['path' => '/tmp/_theme.scss', 'content' => ''];

        $this->module->handleUse(new UseNode('theme', 'theme'), $env);

        expect($this->ctx->moduleState->loadedModules['theme'])->toBe($moduleData)
            ->and($env->getCurrentScope()->getModule('theme'))->toBe($scope)
            ->and($env->getGlobalScope()->getModule('theme'))->toBe($scope);
    });

    it('handleUse() throws for circular module dependencies', function () {
        $env = new Environment();

        $this->loader->files['theme'] = ['path' => '/tmp/_theme.scss', 'content' => ''];
        $this->ctx->moduleState->loadingFiles['/tmp/_theme.scss'] = true;

        expect(fn() => $this->module->handleUse(new UseNode('theme', 'theme'), $env))
            ->toThrow(ModuleResolutionException::class);
    });
});
