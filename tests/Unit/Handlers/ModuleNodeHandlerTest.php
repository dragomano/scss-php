<?php

declare(strict_types=1);

use Bugo\SCSS\Handlers\ModuleNodeHandler;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\States\ModuleState;
use Tests\RuntimeFactory;

it('handles @import, @forward and @use css emission', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();

    $imported  = $runtime->moduleLoad()->handleImport(new ImportNode(['"_imported.scss"']), $ctx);
    $forwarded = $runtime->moduleLoad()->handleForward(new ForwardNode('_forwarded.scss'), $ctx);

    $use = new UseNode('_configurable_with_css.scss', 'cfg');

    $runtime->module()->handleUse($use, $ctx->env);

    $used      = $runtime->moduleLoad()->handleUse($use, $ctx);
    $usedAgain = $runtime->moduleLoad()->handleUse($use, $ctx);

    expect($imported)->toContain('.from-import')
        ->and($forwarded)->toContain('.from-forwarded')
        ->and($used)->toContain('.configurable-sample')
        ->and($usedAgain)->toBe('');
});

it('does not emit forwarded css twice for the same module', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();

    $node = new ForwardNode('_forwarded.scss');

    $first  = $runtime->moduleLoad()->handleForward($node, $ctx);
    $second = $runtime->moduleLoad()->handleForward($node, $ctx);

    expect($first)->toContain('.from-forwarded')
        ->and($second)->toBe('');
});

it('adds newlines between sequential css and sass imports', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();

    $cssImports = $runtime->moduleLoad()->handleImport(
        new ImportNode(['"a.css"', '"b.css"']),
        $ctx,
    );

    $sassImports = $runtime->moduleLoad()->handleImport(
        new ImportNode(['"_imported.scss"', '"_forwarded.scss"']),
        $ctx,
    );

    expect($cssImports)->toBe("@import \"a.css\";\n@import \"b.css\";")
        ->and($sassImports)->toContain(".from-import {\n  value: imported;\n}\n.from-forwarded {");
});

it('adds a newline between css and sass imports in the same directive', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();

    $result = $runtime->moduleLoad()->handleImport(
        new ImportNode(['"a.css"', '"_imported.scss"']),
        $ctx,
    );

    expect($result)->toContain("@import \"a.css\";\n.from-import {");
});

it('qualifies imported sass css with the current parent selector', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();

    $ctx->env->getCurrentScope()->setVariable('__parent_selector', new StringNode('.wrapper'));

    $result = $runtime->moduleLoad()->handleImport(new ImportNode(['"_imported.scss"']), $ctx);

    expect($result)->toContain('.wrapper .from-import');
});

it('does not emit used css twice for the same module', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();

    $use = new UseNode('_configurable_with_css.scss', 'cfg');

    $first  = $runtime->moduleLoad()->handleUse($use, $ctx);
    $second = $runtime->moduleLoad()->handleUse($use, $ctx);

    expect($first)->toContain('.configurable-sample')
        ->and($second)->toBe('');
});

it('returns empty string when namespace is absent from loaded modules state', function () {
    // Module::handleUse() always populates loadedModules for non-sass:, non-wildcard paths.
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();
    $use     = new UseNode('_configurable_with_css.scss', 'cfg');

    $moduleState = new ModuleState();

    $module = mock(Module::class);
    $module->shouldReceive('handleUse')->once();
    $module->shouldReceive('moduleState')->andReturn($moduleState);

    $handler = new ModuleNodeHandler(
        $runtime->evaluation(),
        $module,
        $runtime->render(),
        $runtime->selector(),
    );

    expect($handler->handleUse($use, $ctx))->toBe('');
});
