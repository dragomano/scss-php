<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\UseNode;
use Tests\RuntimeFactory;

it('handles @import, @forward and @use css emission', function () {
    $runtime = RuntimeFactory::createRuntime([__DIR__ . '/../../fixtures']);
    $ctx     = RuntimeFactory::context();

    $imported  = $runtime->moduleLoad()->handleImport(new ImportNode(['"_imported.scss"']), $ctx);
    $forwarded = $runtime->moduleLoad()->handleForward(new ForwardNode('_forwarded.scss'), $ctx);

    $use = new UseNode('_configurable_with_css.scss', 'cfg');
    $runtime->module()->handleUse($use, $ctx->env);
    $used      = $runtime->moduleLoad()->handleUse($use);
    $usedAgain = $runtime->moduleLoad()->handleUse($use);

    expect($imported)->toContain('.from-import')
        ->and($forwarded)->toContain('.from-forwarded')
        ->and($used)->toContain('.configurable-sample')
        ->and($usedAgain)->toBe('');
});
