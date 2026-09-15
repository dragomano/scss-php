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

    it('detects special css function names', function () {
        expect(NameHelper::isSpecialCssFunctionName('element'))->toBeTrue()
            ->and(NameHelper::isSpecialCssFunctionName('expression'))->toBeTrue()
            ->and(NameHelper::isSpecialCssFunctionName('type'))->toBeTrue()
            ->and(NameHelper::isSpecialCssFunctionName('ELEMENT'))->toBeTrue()
            ->and(NameHelper::isSpecialCssFunctionName('calc'))->toBeFalse()
            ->and(NameHelper::isSpecialCssFunctionName('-x-calc'))->toBeTrue()
            ->and(NameHelper::isSpecialCssFunctionName('-x-element'))->toBeTrue()
            ->and(NameHelper::isSpecialCssFunctionName('-x-expression'))->toBeTrue()
            ->and(NameHelper::isSpecialCssFunctionName('-x-unknown'))->toBeFalse()
            ->and(NameHelper::isSpecialCssFunctionName('-calc'))->toBeFalse();
    });
})->covers(NameHelper::class);
