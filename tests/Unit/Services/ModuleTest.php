<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\LoaderInterface;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\States\LoadedModule;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

describe('Module service', function () {
    beforeEach(function () {
        $this->loader = new class implements LoaderInterface {
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

        $this->parser = new class implements ParserInterface {
            public function setTrackSourceLocations(bool $track): void {}

            public function parse(string $source): RootNode
            {
                return new RootNode([]);
            }
        };

        $this->runtime = RuntimeFactory::createRuntime(
            loader: $this->loader,
            parser: $this->parser,
        );

        $this->module = $this->runtime->module();
        $this->ctx    = (new ReflectionAccessor($this->runtime))->getProperty('ctx');
    });

    it('assignModuleVariable() throws when module cannot be resolved', function () {
        $env = new Environment();

        expect(fn() => $this->module->assignModuleVariable(
            new ModuleVarDeclarationNode('missing', 'color', new StringNode('red')),
            $env,
        ))->toThrow(ModuleResolutionException::class);
    });

    it('assignModuleVariable() throws for private module variables', function () {
        $env = new Environment();
        $env->getCurrentScope()->addModule('theme', new Scope());

        expect(fn() => $this->module->assignModuleVariable(
            new ModuleVarDeclarationNode('theme', '-secret', new StringNode('red')),
            $env,
        ))->toThrow(UndefinedSymbolException::class);
    });

    it('incrementCallDepth() throws when include recursion limit is reached', function () {
        $this->ctx->moduleState->callDepth = 100;

        expect(fn() => $this->module->incrementCallDepth())
            ->toThrow(MaxIterationsExceededException::class);
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
            $env,
        ))->toThrow(
            ModuleResolutionException::class,
            "This variable isn't declared with !default in the target stylesheet, so it can't be configured: \$color (in module '/tmp/_theme.scss').",
        );
    });

    it('handleUse() reuses already loaded modules by file id', function () {
        $env    = new Environment();
        $scope  = new Scope();
        $module = new LoadedModule('/tmp/_theme.scss', $scope, '');

        $this->ctx->moduleState->addByNamespace('/tmp/_theme.scss', $module);
        $this->loader->files['theme'] = ['path' => '/tmp/_theme.scss', 'content' => ''];

        $this->module->handleUse(new UseNode('theme', 'theme'), $env);

        expect($this->ctx->moduleState->getByNamespace('theme'))->toBeInstanceOf(LoadedModule::class)
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

    it('resolveImport() returns css import for empty input', function () {
        expect($this->module->resolveImport(''))->toBe(['type' => 'css', 'raw' => '']);
    });

    it('resolveImport() keeps unterminated quoted imports as raw css', function () {
        expect($this->module->resolveImport('"theme'))->toBe(['type' => 'css', 'raw' => '"theme']);
    });

    it('resolveImport() keeps unquoted imports with media queries as css', function () {
        expect($this->module->resolveImport('theme screen and (color)'))
            ->toBe(['type' => 'css', 'raw' => 'theme screen and (color)']);
    });

    it('resolveImport() keeps unquoted css paths as css', function () {
        expect($this->module->resolveImport('theme.css'))
            ->toBe(['type' => 'css', 'raw' => 'theme.css']);
    });

    it('resolveImport() resolves plain unquoted sass imports as sass', function () {
        expect($this->module->resolveImport('theme'))
            ->toBe(['type' => 'sass', 'path' => 'theme']);
    });

    it('resolveImport() resolves unquoted sass imports with trailing whitespace as sass', function () {
        expect($this->module->resolveImport('theme '))
            ->toBe(['type' => 'sass', 'path' => 'theme']);
    });

    it('resolveImport() keeps https imports as raw css', function () {
        expect($this->module->resolveImport('https://cdn.example.com/theme'))
            ->toBe(['type' => 'css', 'raw' => 'https://cdn.example.com/theme']);
    });

    it('loadAndEvaluateModule() throws for circular dependencies during import evaluation', function () {
        $this->loader->files['theme'] = ['path' => '/tmp/_theme.scss', 'content' => ''];
        $this->ctx->moduleState->loadingFiles['/tmp/_theme.scss'] = true;

        expect(fn() => $this->module->loadAndEvaluateModule('theme', fromImport: true))
            ->toThrow(ModuleResolutionException::class);
    });

    it('resolveImportForwardConfiguration() returns configuration unchanged when prefix is empty', function () {
        $env = new Environment();
        $config = ['color' => new StringNode('red')];

        $resolved = $this->module->resolveImportForwardConfiguration(
            new ForwardNode('theme'),
            $env,
            $config,
        );

        expect($resolved)->toBe($config);
    });

    it('resolveImportForwardConfiguration() pulls matching prefixed variables from the environment', function () {
        $env = new Environment();
        $env->getCurrentScope()->setVariable('theme-color', new StringNode('red'));
        $env->getCurrentScope()->setVariable('theme-gap', new StringNode('1rem'));
        $env->getCurrentScope()->setVariable('other-value', new StringNode('ignored'));

        $resolved = $this->module->resolveImportForwardConfiguration(
            new ForwardNode('theme', 'theme-'),
            $env,
            ['gap' => new StringNode('preset')],
        );

        expect($resolved)->toHaveKey('color')
            ->and($resolved['color'])->toBeInstanceOf(StringNode::class)
            ->and($resolved['color']->value)->toBe('red')
            ->and($resolved['gap'])->toBeInstanceOf(StringNode::class)
            ->and($resolved['gap']->value)->toBe('preset')
            ->and($resolved)->not->toHaveKey('value');
    });

    it('isCssImportPath() returns false for empty trimmed paths', function () {
        $accessor = new ReflectionAccessor($this->module);

        expect($accessor->callMethod('isCssImportPath', ['   ']))->toBeFalse();
    });
});
