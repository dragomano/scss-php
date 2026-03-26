<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Runtime\Environment;
use Tests\RuntimeFactory;

it('evaluates boolean expressions and compares values', function () {
    $runtime = RuntimeFactory::createRuntime();
    $env = new Environment();

    expect($runtime->condition()->evaluate('1 < 2 and 3 > 1', $env))->toBeTrue()
        ->and($runtime->condition()->compare(new NumberNode(2), '>=', new NumberNode(1), $env))->toBeTrue()
        ->and($runtime->condition()->isTruthy($runtime->evaluation()->createBooleanNode(false)))->toBeFalse();
});

it('caches top level operator splits', function () {
    $runtime = RuntimeFactory::createRuntime();

    $first = $runtime->condition()->splitTopLevelByOperator('a and (b and c)', 'and');
    $second = $runtime->condition()->splitTopLevelByOperator('a and (b and c)', 'and');

    expect($first)->toBe(['a', '(b and c)'])
        ->and($second)->toBe($first);
});
