<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\WarnNode;
use Tests\ArrayLogger;
use Tests\RuntimeFactory;

it('logs debug and warn diagnostics', function () {
    $logger  = new ArrayLogger();
    $runtime = RuntimeFactory::createRuntime(logger: $logger);
    $ctx     = RuntimeFactory::context();

    $runtime->diagnostic()->handleDebug(new DebugNode(new StringNode('hello'), 3, 2), $ctx);
    $runtime->diagnostic()->handleWarn(new WarnNode(new NullNode(), 4, 1), $ctx);

    expect($logger->records)->toHaveCount(2)
        ->and($logger->records[0]['level'])->toBe('debug')
        ->and($logger->records[0]['message'])->toContain('input.scss:3 >>> hello')
        ->and($logger->records[1]['level'])->toBe('warning');
});

it('throws sass error for @error diagnostics', function () {
    $logger  = new ArrayLogger();
    $runtime = RuntimeFactory::createRuntime(logger: $logger);
    $ctx     = RuntimeFactory::context();

    expect(fn() => $runtime->diagnostic()->handleError(new ErrorNode(new StringNode('boom'), 5, 6), $ctx))
        ->toThrow(SassErrorException::class, 'boom');
});
