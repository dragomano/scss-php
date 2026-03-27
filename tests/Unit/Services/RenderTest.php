<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\CommentNode;
use Tests\ReflectionAccessor;
use Tests\RuntimeFactory;

it('renders indentation and trims trailing newlines', function () {
    $runtime = RuntimeFactory::createRuntime();
    $render  = $runtime->render();

    expect($render->indentPrefix(2))->toBe('    ')
        ->and($render->trimTrailingNewlines("a\n\n"))->toBe('a')
        ->and($render->indentLines("a\n\nb", '  '))->toBe("  a\n\n  b");
});

it('collects source mappings and builds a source map', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map', includeSources: true)
    );

    $render = $runtime->render();
    $output = '';

    $runtime->context()->options();

    $runtimeContext = new ReflectionAccessor($runtime);

    $ctx = $runtimeContext->getProperty('ctx');
    $ctx->sourceMapState->collectMappings = true;

    $render->appendChunk($output, '.a', new CommentNode('x', false, 2, 3));

    $map = $render->buildSourceMap($output, '.a {}');

    expect($output)->toBe('.a')
        ->and($map)->toContain('"version":3')
        ->and($map)->toContain('"sourcesContent"');
});
