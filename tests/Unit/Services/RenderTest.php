<?php

declare(strict_types=1);

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\CompilerOptions;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\SourceMappingService;
use Bugo\SCSS\Utils\DeferredChunk;
use Bugo\SCSS\Utils\SourceMapMapping;
use Bugo\SCSS\Utils\SourceMapPosition;
use Bugo\SCSS\Visitor;
use Tests\Support\RuntimeFactory;

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

    $render->appendChunk($output, "\n");

    $mapWithTrailingNewline = $render->buildSourceMap($output, '.a {}');

    expect($output)->toBe(".a\n")
        ->and($map)->toContain('"version":3')
        ->and($map)->toContain('"sourcesContent"')
        ->and($mapWithTrailingNewline)->toContain('"version":3');
});

it('prepends a charset and shifts mappings only for non-ascii output', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map'),
        context: $compilerContext,
    );

    $render = $runtime->render();

    $compilerContext->sourceMapState->mappings = [
        new SourceMapMapping(new SourceMapPosition(1, 0), new SourceMapPosition(1, 0)),
    ];

    // ASCII output keeps the css and mappings untouched.
    expect($render->prependCharset('.a{color:red}'))->toBe('.a{color:red}')
        ->and($compilerContext->sourceMapState->mappings[0]->generated->line)->toBe(1);

    // Non-ASCII output gets the charset prefix and every mapping shifts down one line.
    $result = $render->prependCharset('.a{content:"→"}');

    expect($result)->toBe("@charset \"UTF-8\";\n" . '.a{content:"→"}')
        ->and($compilerContext->sourceMapState->mappings[0]->generated->line)->toBe(2);
});

it('handles render edge cases for chunks source maps and remapping helpers', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map'),
        context: $compilerContext,
    );

    $render = $runtime->render();
    $helper = new SourceMappingService();
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

it('covers edge branches of the source map remapping helper', function () {
    $helper = new SourceMappingService();

    expect($helper->shouldRemapMappingsAfterOptimization(null, 0, 'a', 'b'))->toBeFalse()
        ->and($helper->shouldRemapMappingsAfterOptimization('output.css.map', 10, 'a', 'b'))->toBeTrue();

    $originWithoutPositions = new class implements Visitable {
        public function accept(Visitor $visitor, TraversalContext $ctx): string
        {
            return '';
        }
    };

    $mappings = [];
    $helper->appendMapping($mappings, 1, 0, $originWithoutPositions);

    $invalidOrigin = new class implements Visitable {
        public int $line = 0;

        public int $column = 2;

        public function accept(Visitor $visitor, TraversalContext $ctx): string
        {
            return '';
        }
    };

    $invalidMappings = [];
    $helper->appendMapping($invalidMappings, 1, 0, $invalidOrigin);

    // Deletes a character from the middle of the text.
    $deletionMap = $helper->buildOldToNewOffsetMap('abc', 'ac');

    // Falls back to advancing both cursors on a full mismatch.
    $fallbackMap = $helper->buildOldToNewOffsetMap('ax', 'bx');

    $result = $helper->remapMappingsAfterOptimization(
        [
            new SourceMapMapping(new SourceMapPosition(1, 0), new SourceMapPosition(1, 0)),
            new SourceMapMapping(new SourceMapPosition(2, 1), new SourceMapPosition(1, 0)),
        ],
        "abc\nx\ny",
        "abc\nx!\ny",
    );

    expect($mappings)->toBe([])
        ->and($invalidMappings)->toBe([])
        ->and($deletionMap[1])->toBe(1)
        ->and($deletionMap[2])->toBe(1)
        ->and($fallbackMap[0])->toBe(0)
        ->and($result[0]->generated->line)->toBe(1)
        ->and($result[0]->generated->column)->toBe(0)
        ->and($result[1]->generated->line)->toBe(2)
        ->and($result[1]->generated->column)->toBe(2);

    $runtime = RuntimeFactory::createRuntime();
    $render  = $runtime->render();

    expect($render->indentLines('', '  '))->toBe('')
        ->and($render->indentLines('a', ''))->toBe('a')
        ->and($render->outputSeparator())->toBe("\n");
});

it('shifts generated positions by a prefix and skips empty inputs', function () {
    $helper = new SourceMappingService();

    $mapping = new SourceMapMapping(new SourceMapPosition(1, 4), new SourceMapPosition(2, 0));

    // Empty mappings and empty prefix are both no-ops.
    expect($helper->shiftMappingsByPrefix([], 'prefix'))->toBe([])
        ->and($helper->shiftMappingsByPrefix([$mapping], ''))->toBe([$mapping]);

    // A single-line prefix shifts the column of first-line mappings.
    $inlineShift = $helper->shiftMappingsByPrefix([$mapping], '@import "a.css";');

    expect($inlineShift[0]->generated->line)->toBe(1)
        ->and($inlineShift[0]->generated->column)->toBe(20);

    // A prefix with a newline shifts the line and leaves later-line columns untouched.
    $laterLine = new SourceMapMapping(new SourceMapPosition(2, 3), new SourceMapPosition(3, 0));

    $lineShift = $helper->shiftMappingsByPrefix([$mapping, $laterLine], '@charset "UTF-8";' . "\n");

    expect($lineShift[0]->generated->line)->toBe(2)
        ->and($lineShift[0]->generated->column)->toBe(4)
        ->and($lineShift[1]->generated->line)->toBe(3)
        ->and($lineShift[1]->generated->column)->toBe(3);
});

it('clamps raw mapping source positions to their minimum bounds', function () {
    $helper   = new SourceMappingService();
    $mappings = [];

    // sourceLine below 1 and sourceColumn 0 clamp to (1, 0); the -1 offset applies otherwise.
    $helper->appendRawMapping($mappings, 3, 7, 0, 0);
    $helper->appendRawMapping($mappings, 4, 8, 5, 6);

    expect($mappings[0]->generated->line)->toBe(3)
        ->and($mappings[0]->generated->column)->toBe(7)
        ->and($mappings[0]->original->line)->toBe(1)
        ->and($mappings[0]->original->column)->toBe(0)
        ->and($mappings[1]->original->line)->toBe(5)
        ->and($mappings[1]->original->column)->toBe(5);
});

it('maps trailing deletions when the optimized text is shorter', function () {
    $helper = new SourceMappingService();

    // "bc" is deleted from the end, exercising the tail-fill loop.
    $map = $helper->buildOldToNewOffsetMap('abc', 'a');

    expect($map[0])->toBe(0)
        ->and($map[1])->toBe(1)
        ->and($map[2])->toBe(1)
        ->and($map[3])->toBe(1);
});

it('skips remapping when the optimization gate declines large inputs', function () {
    $compilerContext = new CompilerContext();

    $runtime = RuntimeFactory::createRuntime(
        options: new CompilerOptions(sourceMapFile: 'output.css.map'),
        context: $compilerContext,
    );

    $render  = $runtime->render();
    $mapping = new SourceMapMapping(new SourceMapPosition(1, 0), new SourceMapPosition(1, 0));

    // Over 20000 mappings with a large length delta trips the performance gate to false.
    $compilerContext->sourceMapState->mappings = array_fill(0, 20001, $mapping);

    $render->remapMappingsAfterOptimization(str_repeat('a', 150001), str_repeat('b', 140001));

    expect($compilerContext->sourceMapState->mappings)->toHaveCount(20001)
        ->and($compilerContext->sourceMapState->mappings[0])->toBe($mapping);
});
