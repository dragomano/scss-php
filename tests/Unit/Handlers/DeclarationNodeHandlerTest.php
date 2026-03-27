<?php

declare(strict_types=1);

use Bugo\SCSS\Handlers\DeclarationNodeHandler;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Text;
use Tests\RuntimeFactory;

it('renders declarations with important flag', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context(indent: 1);
    $node    = new DeclarationNode('color', new StringNode('red'), important: true);

    expect($runtime->declaration()->handle($node, $ctx))
        ->toBe('  color: red !important;');
});

it('omits declarations whose value resolves to null', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect($runtime->declaration()->handle(new DeclarationNode('color', new NullNode()), $ctx))
        ->toBe('');
});

it('replaces declaration value with non-strict arithmetic result when available', function () {
    $env = RuntimeFactory::context()->env;
    $ctx = RuntimeFactory::context($env);
    $list = new ListNode([
        new NumberNode(10),
        new StringNode('/'),
        new NumberNode(2),
    ], 'space');
    $resolved = new NumberNode(5);

    $evaluation = mock(Evaluator::class);
    $evaluation->shouldReceive('evaluateDeclarationValue')->once()->with($list, 'width', $env)->andReturn($list);
    $evaluation->shouldReceive('shouldUseCompactSlashSpacing')->once()->with('width')->andReturn(false);
    $evaluation->shouldReceive('evaluateArithmeticList')->once()->with($list, false, $env)->andReturn($resolved);
    $evaluation->shouldReceive('isSassNullValue')->once()->with($resolved)->andReturn(false);
    $evaluation->shouldReceive('shouldCompressNamedColorForProperty')->once()->with('width')->andReturn(false);
    $evaluation->shouldReceive('tryEvaluateFormattedDeclarationExpression')->once()->with('width', $resolved, $env)->andReturn(null);
    $evaluation->shouldReceive('format')->once()->with($resolved, $env)->andReturn('5');
    $evaluation->shouldReceive('normalizeDeclarationSlashSpacing')->once()->with('width', '5')->andReturn('5');

    $render = mock(Render::class);
    $render->shouldReceive('indentPrefix')->once()->with(0)->andReturn('');

    $text = mock(Text::class);

    $handler = new DeclarationNodeHandler($evaluation, $render, $text);

    expect($handler->handle(new DeclarationNode('width', $list), $ctx))
        ->toBe('width: 5;');

    Mockery::close();
});

it('interpolates formatted declaration values that still contain interpolation markers', function () {
    $env = RuntimeFactory::context()->env;
    $ctx = RuntimeFactory::context($env);
    $value = new StringNode('placeholder');
    $evaluated = new StringNode('placeholder');

    $evaluation = mock(Evaluator::class);
    $evaluation->shouldReceive('evaluateDeclarationValue')->once()->with($value, 'color', $env)->andReturn($evaluated);
    $evaluation->shouldReceive('isSassNullValue')->once()->with($evaluated)->andReturn(false);
    $evaluation->shouldReceive('shouldCompressNamedColorForProperty')->once()->with('color')->andReturn(false);
    $evaluation->shouldReceive('tryEvaluateFormattedDeclarationExpression')->once()->with('color', $evaluated, $env)->andReturn(null);
    $evaluation->shouldReceive('format')->once()->with($evaluated, $env)->andReturn('#{$name}');
    $evaluation->shouldReceive('normalizeDeclarationSlashSpacing')->once()->with('color', '#{$name}')->andReturn('#{$name}');

    $render = mock(Render::class);
    $render->shouldReceive('indentPrefix')->once()->with(0)->andReturn('');

    $text = mock(Text::class);
    $text->shouldReceive('interpolateText')->once()->with('#{$name}', $env)->andReturn('blue');

    $handler = new DeclarationNodeHandler($evaluation, $render, $text);

    expect($handler->handle(new DeclarationNode('color', $value), $ctx))
        ->toBe('color: blue;');

    Mockery::close();
});
