<?php

declare(strict_types=1);

use Bugo\SCSS\States\ExtendsState;

describe('ExtendsState', function () {
    it('enters an import module scope from the import root registry', function () {
        $state = new ExtendsState();
        $state->extendMap['.a'] = [0 => ['source' => '.b', 'priority' => 1]];
        $state->moduleScopesImport['root.scss']['mod'] = $state->captureScope();

        $state->extendMap = [];

        $previous = $state->enterImportModuleScope('root.scss', 'mod');

        expect($previous)->toBeArray()
            ->and($previous['extendMap'])->toBe([])
            ->and($state->extendMap)->toBe(['.a' => [0 => ['source' => '.b', 'priority' => 1]]]);
    });

    it('falls back to the plain module scope when the import root has no entry', function () {
        $state = new ExtendsState();
        $state->extendMap['.a'] = [0 => ['source' => '.b', 'priority' => 1]];
        $state->moduleScopes['mod'] = $state->captureScope();

        $state->extendMap = [];

        $previous = $state->enterImportModuleScope('root.scss', 'mod');

        expect($previous)->toBeArray()
            ->and($state->extendMap)->toBe(['.a' => [0 => ['source' => '.b', 'priority' => 1]]]);
    });

    it('returns null when no module scope is registered', function () {
        $state = new ExtendsState();

        expect($state->enterImportModuleScope('root.scss', 'mod'))->toBeNull();
    });

    it('leaveModuleScope() restores the previous scope and ignores null', function () {
        $state = new ExtendsState();
        $state->extendMap['.a'] = [0 => ['source' => '.b', 'priority' => 1]];
        $state->moduleScopes['mod'] = $state->captureScope();

        $state->extendMap = [];

        $previous = $state->enterImportModuleScope('root.scss', 'mod');

        $state->leaveModuleScope(null);

        expect($state->extendMap)->toBe(['.a' => [0 => ['source' => '.b', 'priority' => 1]]]);

        $state->leaveModuleScope($previous);

        expect($state->extendMap)->toBe([]);
    });
})->covers(ExtendsState::class);
