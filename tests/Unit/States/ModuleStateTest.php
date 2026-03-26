<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\States\ModuleState;

describe('ModuleState', function () {
    it('initializes with default values', function () {
        $state = new ModuleState();

        expect($state->loadedModules)->toBe([])
            ->and($state->loadedModulesById)->toBe([])
            ->and($state->importedModules)->toBe([])
            ->and($state->forwardedModules)->toBe([])
            ->and($state->emittedForwardCss)->toBe([])
            ->and($state->emittedUseCss)->toBe([])
            ->and($state->importEvaluationDepth)->toBe(0)
            ->and($state->callDepth)->toBe(0)
            ->and($state->hasUseDirective)->toBeFalse()
            ->and($state->hasSassImport)->toBeFalse()
            ->and($state->loadingFiles)->toBe([]);
    });

    it('reset() restores default values', function () {
        $state = new ModuleState();
        $scope = new Scope();

        $state->loadedModules['mod'] = ['id' => 'x', 'scope' => $scope, 'css' => ''];
        $state->importEvaluationDepth = 3;
        $state->callDepth = 5;
        $state->hasUseDirective = true;
        $state->hasSassImport = true;
        $state->loadingFiles['file.scss'] = true;
        $state->emittedForwardCss['x'] = true;
        $state->emittedUseCss['y'] = true;

        $state->reset();

        expect($state->loadedModules)->toBe([])
            ->and($state->loadedModulesById)->toBe([])
            ->and($state->importedModules)->toBe([])
            ->and($state->forwardedModules)->toBe([])
            ->and($state->emittedForwardCss)->toBe([])
            ->and($state->emittedUseCss)->toBe([])
            ->and($state->importEvaluationDepth)->toBe(0)
            ->and($state->callDepth)->toBe(0)
            ->and($state->hasUseDirective)->toBeFalse()
            ->and($state->hasSassImport)->toBeFalse()
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
});
