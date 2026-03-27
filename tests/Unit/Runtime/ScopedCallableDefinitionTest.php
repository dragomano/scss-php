<?php

declare(strict_types=1);

use Bugo\SCSS\Runtime\CallableDefinition;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\Runtime\ScopedCallableDefinition;

describe('ScopedCallableDefinition', function () {
    it('line() returns the definition line number', function () {
        $scope  = new Scope();
        $def    = new CallableDefinition([], [], $scope, 42);
        $scoped = new ScopedCallableDefinition($def, $scope);

        expect($scoped->line())->toBe(42);
    });

    it('isCapturedOutsideScope() returns false when scope matches closure scope', function () {
        $scope  = new Scope();
        $def    = new CallableDefinition([], [], $scope, 1);
        $scoped = new ScopedCallableDefinition($def, $scope);

        expect($scoped->isCapturedOutsideScope())->toBeFalse();
    });

    it('isCapturedOutsideScope() returns true when scope differs from closure scope', function () {
        $closureScope  = new Scope();
        $callSite      = new Scope();
        $def           = new CallableDefinition([], [], $closureScope, 1);
        $scoped        = new ScopedCallableDefinition($def, $callSite);

        expect($scoped->isCapturedOutsideScope())->toBeTrue();
    });

    it('exposes the original definition', function () {
        $scope  = new Scope();
        $def    = new CallableDefinition([], [], $scope, 7);
        $scoped = new ScopedCallableDefinition($def, $scope);

        expect($scoped->definition)->toBe($def);
    });
});
