<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\StatementNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Utils\StringEscapeDecoder;
use Bugo\SCSS\Values\ValueFactory;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function basename;
use function file_put_contents;
use function implode;
use function str_contains;

final readonly class Compiler implements CompilerInterface
{
    private CompilerContext $ctx;

    private CompilerRuntime $runtime;

    public function __construct(
        private CompilerOptions $options = new CompilerOptions(),
        private LoaderInterface $loader = new Loader(),
        private ParserInterface $parser = new Parser(),
        private LoggerInterface $logger = new NullLogger(),
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

        $syntax ??= Syntax::SCSS;
        $output   = '';

        try {
            $this->ctx->outputState->hoistCssImports = true;

            $source = $this->normalizeSource($source, $syntax);

            $this->parser->setPlainCss($syntax === Syntax::CSS);

            try {
                $ast = $this->parse($source);
            } finally {
                $this->parser->setPlainCss(false);
            }

            $environment = $this->buildEnvironment($ast, str_contains($source, '@extend'));
            $compiled    = $this->compileAst($ast, $environment);
            $output      = $this->postProcess($compiled, $source);
        } finally {
            $this->resetState();
        }

        return $output;
    }

    public function compileFile(string $path): string
    {
        $loaded = $this->loader->load($path);

        $sourceFile = $this->options->sourceFile !== 'input.scss'
            ? $this->options->sourceFile
            : $path;

        return $this->compileString(
            $loaded->content,
            Syntax::fromPath($path, $loaded->content),
            $sourceFile,
        );
    }

    private function createContext(): CompilerContext
    {
        return new CompilerContext(
            valueFactory: new ValueFactory(
                compressed: $this->options->style === Style::COMPRESSED,
            ),
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

    private function parse(string $source): RootNode
    {
        $this->parser->setTrackSourceLocations(true);

        return $this->parser->parse($source);
    }

    private function buildEnvironment(RootNode $ast, bool $collectExtends): Environment
    {
        $environment = new Environment();

        if ($collectExtends) {
            $this->runtime->selector()->collectExtends($ast, $environment);
        }

        $this->runtime->extendsGraph()->finalize($ast);

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
        $body = $this->options->sourceMapFile !== null && $this->options->style === Style::COMPRESSED
            ? $this->optimizeWithRemappedSourceMap($compiled)
            : $this->optimizeCompiled($compiled);

        $optimized = $this->runtime->render()->prependCharset($body);

        if ($this->options->sourceMapFile !== null) {
            $sourceMap = $this->runtime->render()->buildSourceMap($optimized, $source);

            file_put_contents($this->options->sourceMapFile, $sourceMap);

            return $optimized . "\n/*# sourceMappingURL=" . $this->options->sourceMapFile . ' */';
        }

        return $optimized;
    }

    private function optimizeCompiled(string $compiled): string
    {
        $cssImports = $this->ctx->outputState->cssImports;

        if ($cssImports !== []) {
            $compiled = implode("\n", $cssImports)
                . ($compiled !== '' ? "\n" . $compiled : '');
        }

        return $this->runtime->render()->optimizeBody(
            StringEscapeDecoder::restoreHashes($compiled),
        );
    }

    private function optimizeWithRemappedSourceMap(string $compiled): string
    {
        $render = $this->runtime->render();
        $body   = $render->optimizeBody(StringEscapeDecoder::restoreHashes($compiled));

        // Generated positions are tracked against the raw compiled text, so it is the diff baseline.
        // Prefixes are applied outside the diff window as a line/column shift.
        $render->remapMappingsAfterOptimization($compiled, $body);

        $prefix = $this->buildImportPrefix($this->ctx->outputState->cssImports);

        if ($prefix !== '') {
            $render->shiftMappingsByPrefix($prefix);
        }

        return $prefix . $body;
    }

    /**
     * @param array<int, string> $cssImports
     */
    private function buildImportPrefix(array $cssImports): string
    {
        if ($cssImports === []) {
            return '';
        }

        return $this->runtime->render()->optimizeBody(implode("\n", $cssImports));
    }

    private function normalizeSource(string $source, Syntax $syntax): string
    {
        return $this->ctx->normalizerPipeline->process($source, $syntax);
    }
}
