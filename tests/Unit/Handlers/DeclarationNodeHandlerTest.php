<?php

declare(strict_types=1);

use Bugo\SCSS\Exceptions\SassErrorException;
use Bugo\SCSS\Handlers\DeclarationNodeHandler;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MapPair;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\AtRuleContextEntry;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Text;
use Bugo\SCSS\States\OutputState;
use Tests\Support\RuntimeFactory;

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
    $evaluation->shouldReceive('tryEvaluateFormattedDeclarationExpression')->once()->with('width', $resolved, $env, null)->andReturn(null);
    $evaluation->shouldReceive('format')->once()->with($resolved, $env)->andReturn('5');
    $evaluation->shouldReceive('normalizeDeclarationSlashSpacing')->once()->with('width', '5')->andReturn('5');

    $render = mock(Render::class);
    $render->shouldReceive('indentPrefix')->once()->with(0)->andReturn('');
    $render->shouldReceive('outputState')->once()->andReturn(new OutputState());
    $render->shouldReceive('collectSourceMappings')->once()->andReturn(false);

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
    $evaluation->shouldReceive('format')->once()->with($evaluated, $env)->andReturn('#{$name}');
    $evaluation->shouldReceive('normalizeDeclarationSlashSpacing')->once()->with('color', '#{$name}')->andReturn('#{$name}');

    $render = mock(Render::class);
    $render->shouldReceive('indentPrefix')->once()->with(0)->andReturn('');
    $render->shouldReceive('outputState')->once()->andReturn(new OutputState());
    $render->shouldReceive('collectSourceMappings')->once()->andReturn(false);

    $text = mock(Text::class);
    $text->shouldReceive('interpolateText')->once()->with('#{$name}', $env)->andReturn('blue');

    $handler = new DeclarationNodeHandler($evaluation, $render, $text);

    expect($handler->handle(new DeclarationNode('color', $value), $ctx))
        ->toBe('color: blue;');

    Mockery::close();
});

it('does not reject bare declarations when the flow-control guard is not strictly true', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $ctx->env->getCurrentScope()->setVariableLocal('__flow_control_declaration_guard', 1);

    expect($runtime->declaration()->handle(new DeclarationNode('color', new StringNode('red')), $ctx))
        ->toBe('color: red;');
});

it('rejects bare declarations when the at-rule stack variable is not an array', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $scope   = $ctx->env->getCurrentScope();

    $scope->setVariableLocal('__flow_control_declaration_guard', true);
    $scope->setVariableLocal('__at_rule_stack', 'invalid');

    expect(fn() => $runtime->declaration()->handle(new DeclarationNode('color', new StringNode('red')), $ctx))
        ->toThrow(SassErrorException::class, 'Expected identifier.');
});

it('rejects bare declarations when the at-rule stack is empty', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $scope   = $ctx->env->getCurrentScope();

    $scope->setVariableLocal('__flow_control_declaration_guard', true);
    $scope->setVariableLocal('__at_rule_stack', []);

    expect(fn() => $runtime->declaration()->handle(new DeclarationNode('color', new StringNode('red')), $ctx))
        ->toThrow(SassErrorException::class, 'Expected identifier.');
});

it('rejects bare declarations when the last at-rule stack entry is not a directive entry', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $scope   = $ctx->env->getCurrentScope();

    $scope->setVariableLocal('__flow_control_declaration_guard', true);
    $scope->setVariableLocal('__at_rule_stack', [
        AtRuleContextEntry::supports('(display: grid)'),
    ]);

    expect(fn() => $runtime->declaration()->handle(new DeclarationNode('color', new StringNode('red')), $ctx))
        ->toThrow(SassErrorException::class, 'Expected identifier.');
});

it('allows bare declarations for descriptor-compatible directive contexts and rejects other directives', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();
    $scope   = $ctx->env->getCurrentScope();

    $scope->setVariableLocal('__flow_control_declaration_guard', true);
    $scope->setVariableLocal('__at_rule_stack', [
        AtRuleContextEntry::directive('font-face'),
    ]);

    expect($runtime->declaration()->handle(new DeclarationNode('color', new StringNode('red')), $ctx))
        ->toBe('color: red;');

    $scope->setVariableLocal('__at_rule_stack', [
        AtRuleContextEntry::directive('media'),
    ]);

    expect(fn() => $runtime->declaration()->handle(new DeclarationNode('color', new StringNode('red')), $ctx))
        ->toThrow(SassErrorException::class, 'Expected identifier.');
});

it('skips blank lines while reindenting custom property values', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = RuntimeFactory::context();

    expect($runtime->declaration()->handle(new DeclarationNode('--x', new StringNode("1px\n\n2px")), $ctx))
        ->toBe("--x:1px\n\n2px;");
});

it('preserves sass null arguments in raw css function values when plain css is active', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = new TraversalContext(new Environment(), 0, true);

    expect($runtime->declaration()->handle(
        new DeclarationNode('color', new FunctionNode('fn', [new NullNode()])),
        $ctx,
    ))->toBe('color: fn(null);');
});

it('preserves sass null entries in raw css map values when plain css is active', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = new TraversalContext(new Environment(), 0, true);

    expect($runtime->declaration()->handle(
        new DeclarationNode('color', new MapNode([
            new MapPair(new StringNode('a'), new NullNode()),
        ])),
        $ctx,
    ))->toBe('color: (a: null);');
});

it('renders named arguments inside raw css function values when plain css is active', function () {
    $runtime = RuntimeFactory::createRuntime();
    $ctx     = new TraversalContext(new Environment(), 0, true);

    expect($runtime->declaration()->handle(
        new DeclarationNode('color', new FunctionNode('fn', [
            new NamedArgumentNode('a', new NumberNode(1)),
            new NullNode(),
        ])),
        $ctx,
    ))->toBe('color: fn($a: 1, null);');
});
