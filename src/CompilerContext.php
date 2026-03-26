<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Builtins\Color\ColorBundleAdapter;
use Bugo\SCSS\Builtins\Color\ColorSerializerAdapter;
use Bugo\SCSS\Builtins\FunctionRegistry;
use Bugo\SCSS\Contracts\Color\ColorBundleInterface;
use Bugo\SCSS\Contracts\Color\ColorSerializerInterface;
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
    public FunctionRegistry $functionRegistry;

    public function __construct(
        ?FunctionRegistry $functionRegistry = null,
        public ValueFactory $valueFactory = new ValueFactory(),
        public NormalizerPipeline $normalizerPipeline = new NormalizerPipeline(),
        public ModuleState $moduleState = new ModuleState(),
        public OutputState $outputState = new OutputState(),
        public ConditionCacheState $conditionCacheState = new ConditionCacheState(),
        public SourceMapState $sourceMapState = new SourceMapState(),
        public SourceMapGenerator $sourceMapGenerator = new SourceMapGenerator(),
        public ColorSerializerInterface $colorSerializer = new ColorSerializerAdapter(),
        public ColorBundleInterface $colorBundle = new ColorBundleAdapter(),
        public OutputOptimizer $optimizer = new OutputOptimizer(),
        public OutputRenderer $renderer = new OutputRenderer(),
        public string $currentSourceFile = 'input.scss'
    ) {
        $this->functionRegistry = $functionRegistry ?? new FunctionRegistry(colorBundle: $this->colorBundle);
    }
}
