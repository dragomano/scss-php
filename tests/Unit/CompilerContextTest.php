<?php

declare(strict_types=1);

use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Normalizers\NormalizerPipeline;
use Bugo\SCSS\States\ConditionCacheState;
use Bugo\SCSS\States\ModuleState;
use Bugo\SCSS\States\OutputState;
use Bugo\SCSS\States\SourceMapState;
use Bugo\SCSS\Utils\OutputOptimizer;
use Bugo\SCSS\Utils\OutputRenderer;
use Bugo\SCSS\Utils\SourceMapGenerator;
use Bugo\SCSS\Values\ValueFactory;

describe('CompilerContext', function () {
    it('creates with default dependencies', function () {
        $ctx = new CompilerContext();

        expect($ctx->functionRegistry)->toBeInstanceOf(FunctionRegistry::class)
            ->and($ctx->valueFactory)->toBeInstanceOf(ValueFactory::class)
            ->and($ctx->normalizerPipeline)->toBeInstanceOf(NormalizerPipeline::class)
            ->and($ctx->moduleState)->toBeInstanceOf(ModuleState::class)
            ->and($ctx->outputState)->toBeInstanceOf(OutputState::class)
            ->and($ctx->conditionCacheState)->toBeInstanceOf(ConditionCacheState::class)
            ->and($ctx->sourceMapState)->toBeInstanceOf(SourceMapState::class)
            ->and($ctx->sourceMapGenerator)->toBeInstanceOf(SourceMapGenerator::class)
            ->and($ctx->optimizer)->toBeInstanceOf(OutputOptimizer::class)
            ->and($ctx->renderer)->toBeInstanceOf(OutputRenderer::class);
    });

    it('has default source file name', function () {
        $ctx = new CompilerContext();

        expect($ctx->currentSourceFile)->toBe('input.scss');
    });

    it('accepts custom source file name', function () {
        $ctx = new CompilerContext(currentSourceFile: 'style.scss');

        expect($ctx->currentSourceFile)->toBe('style.scss');
    });

    it('allows overriding dependencies', function () {
        $registry = new FunctionRegistry();
        $ctx      = new CompilerContext(functionRegistry: $registry);

        expect($ctx->functionRegistry)->toBe($registry);
    });

    it('stores explicitly provided value factory as is', function () {
        $valueFactory = new ValueFactory(outputHexColors: true);
        $ctx          = new CompilerContext(valueFactory: $valueFactory);

        expect($ctx->valueFactory)->toBe($valueFactory)
            ->and($ctx->valueFactory->fromAst(new ColorNode('rgb(255, 0, 0)'))->toCss())
            ->toBe('#f00');
    });
});
