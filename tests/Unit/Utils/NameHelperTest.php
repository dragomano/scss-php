<?php

declare(strict_types=1);

use Bugo\SCSS\Utils\NameHelper;

it('returns null member for unqualified names', function () {
    expect(NameHelper::splitQualifiedName('math'))->toBe([
        'namespace' => 'math',
        'member'    => null,
    ]);
})->covers(NameHelper::class);

it('splits qualified names into namespace and member', function () {
    expect(NameHelper::splitQualifiedName('math.round'))->toBe([
        'namespace' => 'math',
        'member'    => 'round',
    ]);
})->covers(NameHelper::class);

it('detects whether name contains a namespace separator', function () {
    expect(NameHelper::hasNamespace('math.round'))->toBeTrue()
        ->and(NameHelper::hasNamespace('math'))->toBeFalse();
})->covers(NameHelper::class);
