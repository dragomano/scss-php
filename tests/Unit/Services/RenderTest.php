<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\RenderSourceMapHelper;
use Bugo\SCSS\Utils\DeferredChunk;
use Bugo\SCSS\Utils\SourceMapMapping;
use Bugo\SCSS\Utils\SourceMapPosition;
use Bugo\SCSS\Visitor;
use Tests\RuntimeFactory;

it('renders indentation and trims trailing newlines', function () {
    $runtime = RuntimeFactory::createRuntime();
    $render  = $runtime->render();

    expect($render->indentPrefix(2))->toBe('    ')
        ->and($render->trimTrailingNewlines("a\n\n"))->toBe('a')
        ->and($render->indentLines("a\n\nb", '  '))->toBe("  a\n\n  b");
});

it('collects source mappings and builds a source map', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map', includeSources: true),
        context: $compilerContext,
    );

    $render = $runtime->render();
    $output = '';

    $runtime->context()->options();

    $compilerContext->sourceMapState->collectMappings = true;

    $render->appendChunk($output, '.a', new CommentNode('x', false, 2, 3));

    $map = $render->buildSourceMap($output, '.a {}');

    expect($output)->toBe('.a')
        ->and($map)->toContain('"version":3')
        ->and($map)->toContain('"sourcesContent"');
});

it('handles render edge cases for chunks source maps and remapping helpers', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map'),
        context: $compilerContext,
    );

    $render = $runtime->render();
    $helper = new RenderSourceMapHelper();
    $output = 'seed';

    $render->appendChunk($output, '');

    expect($output)->toBe('seed');

    $compilerContext->sourceMapState->collectMappings = true;
    $compilerContext->sourceMapState->generatedLine   = 3;
    $compilerContext->sourceMapState->generatedColumn = 7;

    expect($render->trimAndAdjustState("abc\n"))->toBe('abc')
        ->and($compilerContext->sourceMapState->generatedLine)->toBe(2)
        ->and($compilerContext->sourceMapState->generatedColumn)->toBe(3);

    $compilerContext->sourceMapState->collectMappings = false;

    $owner = new stdClass();

    $render->addPendingValueMapping(2, 4, 5, $owner);

    expect($compilerContext->sourceMapState->pendingValueMappings)->toBe([])
        ->and($helper->shouldRemapMappingsAfterOptimization(
            'output.css.map',
            20001,
            str_repeat('a', 150000),
            str_repeat('b', 150000),
        ))
        ->toBeTrue()
        ->and($helper->shouldRemapMappingsAfterOptimization(
            'output.css.map',
            75001,
            str_repeat('a', 150001),
            str_repeat('b', 156002),
        ))
        ->toBeFalse()
        ->and($helper->shouldRemapMappingsAfterOptimization(
            'output.css.map',
            30000,
            str_repeat('a', 150001),
            str_repeat('b', 150002),
        ))
        ->toBeTrue();

    $invalidOrigin = new class implements Visitable {
        public string $line = 'x';

        public string $column = 'y';

        public function accept(Visitor $visitor, TraversalContext $ctx): string
        {
            return '';
        }
    };

    $mappings = [];

    $helper->appendMapping($mappings, 2, 3, $invalidOrigin);

    expect($mappings)->toBe([])
        ->and($helper->remapMappingsAfterOptimization([], 'before', 'after'))->toBe([]);

    $map = $helper->buildOldToNewOffsetMap('ab', 'aXb');

    expect($map[1])->toBe(2)
        ->and($helper->offsetToLineColumnUsingLineStarts([], 5))->toBe([1, 5]);
});

it('tracks generated positions for multiline chunks', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map'),
        context: $compilerContext,
    );

    $render = $runtime->render();
    $output = '';

    $compilerContext->sourceMapState->collectMappings = true;
    $compilerContext->sourceMapState->generatedLine   = 1;
    $compilerContext->sourceMapState->generatedColumn = 0;

    $render->appendChunk($output, "a\nbc");

    expect($output)->toBe("a\nbc")
        ->and($compilerContext->sourceMapState->generatedLine)->toBe(2)
        ->and($compilerContext->sourceMapState->generatedColumn)->toBe(2);
});

it('remaps multiline deferred chunk mappings using the original column on later lines', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map'),
        context: $compilerContext,
    );

    $render = $runtime->render();
    $output = '';

    $compilerContext->sourceMapState->collectMappings = true;
    $compilerContext->sourceMapState->generatedLine   = 10;
    $compilerContext->sourceMapState->generatedColumn = 4;

    $deferred = new DeferredChunk(
        "x\ny",
        1,
        2,
        [
            new SourceMapMapping(new SourceMapPosition(1, 3), new SourceMapPosition(2, 3)),
            new SourceMapMapping(new SourceMapPosition(2, 5), new SourceMapPosition(4, 6)),
        ],
    );

    $render->appendDeferredChunk($output, $deferred);

    expect($output)->toBe("x\ny")
        ->and($compilerContext->sourceMapState->mappings)->toHaveCount(2)
        ->and($compilerContext->sourceMapState->mappings[0]->generated->line)->toBe(10)
        ->and($compilerContext->sourceMapState->mappings[0]->generated->column)->toBe(5)
        ->and($compilerContext->sourceMapState->mappings[1]->generated->line)->toBe(11)
        ->and($compilerContext->sourceMapState->mappings[1]->generated->column)->toBe(5);
});
