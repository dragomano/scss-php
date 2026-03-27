<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Handlers\DiagnosticNodeHandler;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
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

it('throws sass error for error directives handled through the public dispatcher', function () {
    $logger  = new ArrayLogger();
    $runtime = RuntimeFactory::createRuntime(logger: $logger);
    $ctx     = RuntimeFactory::context();

    expect(fn() => $runtime->diagnostic()->handleDirective('error', new StringNode('boom'), $ctx))
        ->toThrow(SassErrorException::class, 'boom');
});

it('uses non-strict arithmetic result when diagnostic message evaluates to a list', function () {
    $env  = RuntimeFactory::context()->env;
    $ctx  = RuntimeFactory::context($env);
    $list = new ListNode([new NumberNode(1), new StringNode('+'), new NumberNode(2)], 'space');
    $sum  = new NumberNode(3);

    $logger  = new ArrayLogger();
    $context = new Context(new CompilerContext(), new CompilerOptions(), $logger);

    $evaluation = mock(Evaluator::class);
    $evaluation->shouldReceive('containsSlashToken')->once()->with($list, true)->andReturn(false);
    $evaluation->shouldReceive('evaluateValue')->once()->with($list, $env)->andReturn($list);
    $evaluation->shouldReceive('evaluateArithmeticList')->once()->with($list, false, $env)->andReturn($sum);

    $render = mock(Render::class);
    $render->shouldReceive('format')->once()->with($sum, $env)->andReturn('3');

    $handler = new DiagnosticNodeHandler($context, $evaluation, $render);

    expect($handler->handleDebug(new DebugNode($list, 7, 1), $ctx))->toBe('')
        ->and($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['message'])->toContain('input.scss:7 >>> 3');

    Mockery::close();
});

it('logs diagnostics without location when origin is omitted', function () {
    $logger  = new ArrayLogger();
    $runtime = RuntimeFactory::createRuntime(logger: $logger);
    $ctx     = RuntimeFactory::context();

    $runtime->diagnostic()->handleDirective('debug', new StringNode('manual'), $ctx);

    expect($logger->records)->toHaveCount(1)
        ->and($logger->records[0]['level'])->toBe('debug')
        ->and($logger->records[0]['message'])->toBe('input.scss >>> manual');
});
