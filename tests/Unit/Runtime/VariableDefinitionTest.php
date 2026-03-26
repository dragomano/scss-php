<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\VariableDefinition;

describe('VariableDefinition', function () {
    it('line() returns the stored line number', function () {
        $scope = new Scope();
        $def   = new VariableDefinition($scope, 12);

        expect($def->line())->toBe(12);
    });

    it('exposes the scope via public property', function () {
        $scope = new Scope();
        $def   = new VariableDefinition($scope, 1);

        expect($def->scope)->toBe($scope);
    });
});
