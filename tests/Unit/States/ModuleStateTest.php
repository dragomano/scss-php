<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\States\LoadedModule;
use Bugo\SCSS\States\ModuleState;

describe('ModuleState', function () {
    it('initializes with default values', function () {
        $state = new ModuleState();

        expect($state->getByNamespace('any'))->toBeNull()
            ->and($state->getById('any'))->toBeNull()
            ->and($state->importedModules)->toBe([])
            ->and($state->forwardedModules)->toBe([])
            ->and($state->emittedForwardCss)->toBe([])
            ->and($state->emittedUseCss)->toBe([])
            ->and($state->importEvaluationDepth)->toBe(0)
            ->and($state->callDepth)->toBe(0)
            ->and($state->hasUseDirective)->toBeFalse()
            ->and($state->loadingFiles)->toBe([]);
    });

    it('reset() restores default values', function () {
        $state = new ModuleState();
        $scope = new Scope();

        $state->registerModule('mod', 'x', $scope, '');
        $state->importEvaluationDepth = 3;
        $state->callDepth = 5;
        $state->hasUseDirective = true;
        $state->loadingFiles['file.scss'] = true;
        $state->emittedForwardCss['x'] = true;
        $state->emittedUseCss['y'] = true;

        $state->reset();

        expect($state->getByNamespace('mod'))->toBeNull()
            ->and($state->getById('x'))->toBeNull()
            ->and($state->importedModules)->toBe([])
            ->and($state->forwardedModules)->toBe([])
            ->and($state->emittedForwardCss)->toBe([])
            ->and($state->emittedUseCss)->toBe([])
            ->and($state->importEvaluationDepth)->toBe(0)
            ->and($state->callDepth)->toBe(0)
            ->and($state->hasUseDirective)->toBeFalse()
            ->and($state->loadingFiles)->toBe([]);
    });

    it('can track call depth', function () {
        $state = new ModuleState();

        $state->callDepth++;
        $state->callDepth++;

        expect($state->callDepth)->toBe(2);
    });

    it('can track loading files', function () {
        $state = new ModuleState();

        $state->loadingFiles['style.scss'] = true;

        expect(isset($state->loadingFiles['style.scss']))->toBeTrue()
            ->and(isset($state->loadingFiles['other.scss']))->toBeFalse();
    });

    it('registerModule() stores a module retrievable by namespace and id', function () {
        $state = new ModuleState();
        $scope = new Scope();

        $state->registerModule('theme', '/tmp/_theme.scss', $scope, '.css {}');

        $byNamespace = $state->getByNamespace('theme');
        $byId        = $state->getById('/tmp/_theme.scss');

        expect($byNamespace)->toBeInstanceOf(LoadedModule::class)
            ->and($byNamespace?->id)->toBe('/tmp/_theme.scss')
            ->and($byNamespace?->scope)->toBe($scope)
            ->and($byNamespace?->css)->toBe('.css {}')
            ->and($byId)->toBe($byNamespace);
    });

    it('hasNamespace() returns true only for registered namespaces', function () {
        $state = new ModuleState();
        $scope = new Scope();

        expect($state->hasNamespace('theme'))->toBeFalse();

        $state->registerModule('theme', 'id', $scope, '');

        expect($state->hasNamespace('theme'))->toBeTrue()
            ->and($state->hasNamespace('other'))->toBeFalse();
    });

    it('addByNamespace() re-registers a module under a different namespace', function () {
        $state  = new ModuleState();
        $scope  = new Scope();
        $module = new LoadedModule('id', $scope, 'css');

        $state->addByNamespace('alias', $module);

        expect($state->getByNamespace('alias'))->toBe($module)
            ->and($state->getById('id'))->toBe($module);
    });
});
