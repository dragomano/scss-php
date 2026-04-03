<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Builtins\Color\ColorBundleAdapter;
use Bugo\SCSS\Builtins\Color\ColorSerializerAdapter;
use Bugo\SCSS\Contracts\Color\ColorBundleInterface;
use Bugo\SCSS\Contracts\Color\ColorSerializerInterface;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Values\ValueFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function basename;
use function file_put_contents;

final class Compiler implements CompilerInterface
{
    private readonly CompilerContext $ctx;

    private readonly CompilerRuntime $runtime;

    public function __construct(
        protected CompilerOptions $options = new CompilerOptions(),
        protected LoaderInterface $loader = new Loader(),
        protected ParserInterface $parser = new Parser(),
        protected LoggerInterface $logger = new NullLogger(),
        protected ColorSerializerInterface $colorSerializer = new ColorSerializerAdapter(),
        protected ColorBundleInterface $colorBundle = new ColorBundleAdapter(),
    ) {
        $this->ctx = $this->createContext();

        $this->runtime = new CompilerRuntime(
            $this->ctx,
            $this->options,
            $this->loader,
            $this->parser,
            $this->logger,
        );
    }

    public function compileString(string $source, ?Syntax $syntax = null, string $sourceFile = ''): string
    {
        $this->resetState();

        $this->ctx->currentSourceFile = basename($sourceFile ?: $this->options->sourceFile);

        try {
            $syntax ??= Syntax::SCSS;

            $ast         = $this->parse($source, $syntax);
            $environment = $this->buildEnvironment($ast);
            $compiled    = $this->compileAst($ast, $environment);

            return $this->postProcess($compiled, $source);
        } finally {
            $this->resetState();
        }
    }

    public function compileFile(string $path): string
    {
        $loaded = $this->loader->load($path);

        $sourceFile = $this->options->sourceFile !== 'input.scss'
            ? $this->options->sourceFile
            : $path;

        return $this->compileString($loaded['content'], Syntax::fromPath($path, $loaded['content']), $sourceFile);
    }

    private function createContext(): CompilerContext
    {
        return new CompilerContext(
            valueFactory: new ValueFactory(
                outputHexColors: $this->options->outputHexColors,
                colorSerializer: $this->colorSerializer,
            ),
            colorSerializer: $this->colorSerializer,
            colorBundle: $this->colorBundle,
        );
    }

    private function resetState(): void
    {
        $this->ctx->moduleState->reset();
        $this->ctx->outputState->reset();
        $this->ctx->conditionCacheState->reset();
        $this->ctx->functionRegistry->reset();
        $this->ctx->sourceMapState->reset();
    }

    private function parse(string $source, Syntax $syntax): RootNode
    {
        $source = $this->normalizeSource($source, $syntax);

        $this->parser->setTrackSourceLocations(true);

        return $this->parser->parse($source);
    }

    private function buildEnvironment(RootNode $ast): Environment
    {
        $environment = new Environment();

        $this->runtime->ast()->evaluate($ast, $environment);
        $this->runtime->selector()->collectExtends($ast, $environment);
        $this->runtime->selector()->finalizeCollectedExtends();

        return $environment;
    }

    private function compileAst(StatementNode $ast, Environment $environment): string
    {
        if ($this->options->sourceMapFile !== null) {
            $this->ctx->sourceMapState->startCollection();
        }

        $compiled = $this->runtime->dispatcher()->compile($ast, $environment);

        if ($this->options->sourceMapFile !== null) {
            $this->ctx->sourceMapState->stopCollection();
        }

        return $compiled;
    }

    private function postProcess(string $compiled, string $source): string
    {
        $optimized = $this->runtime->render()->optimize($compiled);

        if ($this->options->sourceMapFile !== null) {
            $sourceMap = $this->runtime->render()->buildSourceMap($optimized, $source);

            file_put_contents($this->options->sourceMapFile, $sourceMap);

            return $optimized . "\n/*# sourceMappingURL=" . $this->options->sourceMapFile . ' */';
        }

        return $optimized;
    }

    private function normalizeSource(string $source, Syntax $syntax): string
    {
        return $this->ctx->normalizerPipeline->process($source, $syntax);
    }
}
