<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Visitor;
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

it('handles render edge cases for chunks source maps and remapping helpers', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map')
    );
    $render   = $runtime->render();
    $ctx      = (new ReflectionAccessor($runtime))->getProperty('ctx');
    $accessor = new ReflectionAccessor($render);
    $output   = 'seed';

    $render->appendChunk($output, '');

    expect($output)->toBe('seed');

    $ctx->sourceMapState->collectMappings = true;
    $ctx->sourceMapState->generatedLine = 3;
    $ctx->sourceMapState->generatedColumn = 7;

    expect($render->trimAndAdjustState("abc\n"))->toBe('abc')
        ->and($ctx->sourceMapState->generatedLine)->toBe(2)
        ->and($ctx->sourceMapState->generatedColumn)->toBe(3);

    $ctx->sourceMapState->collectMappings = false;
    $owner = new stdClass();
    $render->addPendingValueMapping(2, 4, 5, $owner);

    expect($ctx->sourceMapState->pendingValueMappings)->toBe([]);

    $ctx->sourceMapState->mappings = array_fill(0, 20001, 1);
    expect($accessor->callMethod(
        'shouldRemapMappingsAfterOptimization',
        [str_repeat('a', 150000), str_repeat('b', 150000)]
    ))
        ->toBeTrue();

    $ctx->sourceMapState->mappings = array_fill(0, 75001, 1);
    expect($accessor->callMethod(
        'shouldRemapMappingsAfterOptimization',
        [str_repeat('a', 150001), str_repeat('b', 156002)]
    ))
        ->toBeFalse();

    $ctx->sourceMapState->mappings = array_fill(0, 30000, 1);
    expect($accessor->callMethod(
        'shouldRemapMappingsAfterOptimization',
        [str_repeat('a', 150001), str_repeat('b', 150002)]
    ))
        ->toBeTrue();

    $ctx->sourceMapState->mappings = [];

    $invalidOrigin = new class () implements Visitable {
        public string $line = 'x';

        public string $column = 'y';

        public function accept(Visitor $visitor, TraversalContext $ctx): string
        {
            return '';
        }
    };

    $accessor->callMethod('appendMapping', [$invalidOrigin]);

    expect($ctx->sourceMapState->mappings)->toBe([]);

    $accessor->callMethod('remapMappingsAfterOptimization', ['before', 'after']);

    expect($ctx->sourceMapState->mappings)->toBe([]);

    $map = $accessor->callMethod('buildOldToNewOffsetMap', ['ab', 'aXb']);

    expect($map[1])->toBe(2)
        ->and($accessor->callMethod('offsetToLineColumnUsingLineStarts', [[], 5]))->toBe([1, 5]);
});

it('tracks generated positions for multiline chunks', function () {
    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map')
    );
    $render = $runtime->render();
    $ctx    = (new ReflectionAccessor($runtime))->getProperty('ctx');
    $output = '';

    $ctx->sourceMapState->collectMappings = true;
    $ctx->sourceMapState->generatedLine = 1;
    $ctx->sourceMapState->generatedColumn = 0;

    $render->appendChunk($output, "a\nbc");

    expect($output)->toBe("a\nbc")
        ->and($ctx->sourceMapState->generatedLine)->toBe(2)
        ->and($ctx->sourceMapState->generatedColumn)->toBe(2);
});
