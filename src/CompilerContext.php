<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\Iris\Serializers\Serializer;
use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Normalizers\NormalizerPipeline;
use Bugo\SCSS\States\ConditionCacheState;
use Bugo\SCSS\States\ModuleState;
use Bugo\SCSS\States\OutputState;
use Bugo\SCSS\States\SourceMapState;
use Bugo\SCSS\Utils\OutputOptimizer;
use Bugo\SCSS\Utils\OutputRenderer;
use Bugo\SCSS\Utils\SourceMapGenerator;
use Bugo\SCSS\Values\ValueFactory;

final class CompilerContext
{
    public function __construct(
        public FunctionRegistry $functionRegistry = new FunctionRegistry(),
        public ValueFactory $valueFactory = new ValueFactory(),
        public Serializer $colorSerializer = new Serializer(),
        public NormalizerPipeline $normalizerPipeline = new NormalizerPipeline(),
        public ModuleState $moduleState = new ModuleState(),
        public OutputState $outputState = new OutputState(),
        public ConditionCacheState $conditionCacheState = new ConditionCacheState(),
        public SourceMapState $sourceMapState = new SourceMapState(),
        public SourceMapGenerator $sourceMapGenerator = new SourceMapGenerator(),
        public OutputOptimizer $optimizer = new OutputOptimizer(),
        public OutputRenderer $renderer = new OutputRenderer(),
        public string $currentSourceFile = 'input.scss',
    ) {}
}
