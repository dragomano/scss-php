<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\NameHelper;

describe('NameHelper()', function () {
    it('returns null member for unqualified names', function () {
        expect(NameHelper::splitQualifiedName('math'))->toBe([
            'namespace' => 'math',
            'member'    => null,
        ]);
    });

    it('splits qualified names into namespace and member', function () {
        expect(NameHelper::splitQualifiedName('math.round'))->toBe([
            'namespace' => 'math',
            'member'    => 'round',
        ]);
    });

    it('splits namespaced names into namespace and non-null member', function () {
        expect(NameHelper::splitNamespacedName('math.round'))->toBe([
            'namespace' => 'math',
            'member'    => 'round',
        ])->and(NameHelper::splitNamespacedName('math.'))->toBe([
            'namespace' => 'math',
            'member'    => '',
        ]);
    });

    it('detects whether name contains a namespace separator', function () {
        expect(NameHelper::hasNamespace('math.round'))->toBeTrue()
            ->and(NameHelper::hasNamespace('math'))->toBeFalse();
    });
})->covers(NameHelper::class);
