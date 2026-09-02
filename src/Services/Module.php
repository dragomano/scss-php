<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\CompilerContext;
use Bugo\SCSS\Exceptions\CannotModifyBuiltInVariableException;
use Bugo\SCSS\Exceptions\MaxIterationsExceededException;
use Bugo\SCSS\Exceptions\ModuleResolutionException;
use Bugo\SCSS\Exceptions\UndefinedSymbolException;
use Bugo\SCSS\LoaderInterface;
use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\ParserInterface;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\Scope;
use Bugo\SCSS\States\LoadedModule;
use Bugo\SCSS\States\ModuleState;
use Bugo\SCSS\Syntax;
use Bugo\SCSS\Utils\NameNormalizer;
use Throwable;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_unique;
use function dirname;
use function explode;
use function implode;
use function in_array;
use function ksort;
use function ltrim;
use function pathinfo;
use function serialize;
use function str_contains;
use function str_ends_with;
use function str_repeat;
use function str_starts_with;
use function strlen;
use function strtolower;
use function substr;
use function substr_count;
use function trim;

use const PATHINFO_FILENAME;

final readonly class Module
{
    public function __construct(
        private CompilerContext $ctx,
        private LoaderInterface $loader,
        private ParserInterface $parser,
        private Evaluator $evaluation,
        private Selector $selector,
        private NodeDispatcherInterface $dispatcher,
        private PlainCssRenderer $plainCssRenderer,
    ) {}

    public function assignModuleVariable(
        ModuleVarDeclarationNode $node,
        Environment $env,
        bool $evaluateValue = true,
    ): void {
        if ($this->ctx->functionRegistry->isBuiltinAlias($node->module)) {
            throw new CannotModifyBuiltInVariableException();
        }

        $moduleScope = $env->getCurrentScope()->getModule($node->module);

        if (! $moduleScope) {
            throw ModuleResolutionException::notFound($node->module);
        }

        if (NameNormalizer::isPrivate($node->name)) {
            throw UndefinedSymbolException::variableInModule($node->module, $node->name);
        }

        $value  = $evaluateValue ? $this->evaluation->evaluateValue($node->value, $env) : $node->value;
        $origin = $moduleScope->findForwardedVariableOrigin($node->name);

        if ($origin !== null) {
            $originScope = $origin['scope'];
            $originName  = $origin['name'];

            while (true) {
                $originScope->setVariableLocal($originName, $value, $node->default);

                $forwarded = $originScope->findForwardedVariableOrigin($originName);

                if ($forwarded === null) {
                    break;
                }

                $originScope = $forwarded['scope'];
                $originName  = $forwarded['name'];
            }
        } else {
            $moduleScope->setVariableLocal($node->name, $value, $node->default);
        }
    }

    public function state(): ModuleState
    {
        return $this->ctx->moduleState;
    }

    public function incrementCallDepth(): void
    {
        $state = $this->state();

        if ($state->callDepth >= 100) {
            throw new MaxIterationsExceededException('@include');
        }

        $state->callDepth++;
    }

    public function decrementCallDepth(): void
    {
        $this->state()->callDepth--;
    }

    public function handleUse(UseNode $node, Environment $env): void
    {
        $state = $this->state();

        if ($state->importEvaluationDepth === 0) {
            $state->hasUseDirective = true;
        }

        if (str_starts_with($node->path, 'sass:')) {
            if ($node->configuration !== []) {
                throw ModuleResolutionException::builtInModuleConfiguration($node->path);
            }

            $this->ctx->functionRegistry->registerUse($node->path, $node->namespace);

            $moduleName = substr($node->path, 5);
            $alias      = $node->namespace ?? $moduleName;

            if ($alias === '*') {
                return;
            }

            $variables   = $this->ctx->functionRegistry->moduleVariablesByAlias($alias) ?? [];
            $moduleScope = new Scope();

            foreach ($variables as $name => $value) {
                $moduleScope->setVariable($name, $value);
            }

            $env->getCurrentScope()->addModule($alias, $moduleScope);
            $env->getGlobalScope()->addModule($alias, $moduleScope);

            return;
        }

        $namespace = $node->namespace ?? $this->deriveNamespaceFromUsePath($node->path);
        $loaded    = $state->getByNamespace($namespace);

        if ($node->configuration === [] && $loaded !== null) {
            $env->getCurrentScope()->addModule($namespace, $loaded->scope);
            $env->getGlobalScope()->addModule($namespace, $loaded->scope);

            return;
        }

        if ($namespace !== '*' && $env->getCurrentScope()->hasModuleLocal($namespace)) {
            throw ModuleResolutionException::duplicateNamespace($namespace);
        }

        $file = $this->loader->load($node->path);

        $this->loader->addPath(dirname($file['path']));

        $moduleId   = $file['path'];
        $loadedById = $state->getById($moduleId);

        if ($node->configuration === [] && $loadedById !== null) {
            $state->addByNamespace($namespace, $loadedById);

            $env->getCurrentScope()->addModule($namespace, $loadedById->scope);
            $env->getGlobalScope()->addModule($namespace, $loadedById->scope);

            return;
        }

        if ($node->configuration === []) {
            $forwardKey = $this->forwardCacheKey($node->path, [], $env);

            if (isset($state->forwardedModules[$forwardKey])) {
                $moduleData = $state->forwardedModules[$forwardKey];

                $state->addByNamespace($namespace, new LoadedModule($moduleId, $moduleData['scope'], $moduleData['css']));

                $state->emittedUseCss[$moduleId] = true;

                $env->getCurrentScope()->addModule($namespace, $moduleData['scope']);
                $env->getGlobalScope()->addModule($namespace, $moduleData['scope']);

                return;
            }
        }

        if (isset($state->loadingFiles[$moduleId])) {
            throw ModuleResolutionException::circularDependency($moduleId);
        }

        $syntax       = Syntax::fromPath($file['path'], $file['content']);
        $moduleSource = $this->ctx->normalizerPipeline->process($file['content'], $syntax);
        $moduleAst    = $this->parser->parse($moduleSource);

        if ($node->configuration !== []) {
            $defaultVars = $this->collectDefaultVariableNames($moduleAst);

            foreach (array_keys($node->configuration) as $configName) {
                if (! isset($defaultVars[NameNormalizer::normalize($configName)])) {
                    throw ModuleResolutionException::nonConfigurableVariable($configName, $moduleId);
                }
            }
        }

        $moduleEnv = new Environment();

        $incomingConfig = [];

        foreach ($node->configuration as $name => $valueNode) {
            $value = $this->evaluation->evaluateValue($valueNode, $env);

            $moduleEnv->getCurrentScope()->setVariable($name, $value);

            $incomingConfig[$name] = $value;
        }

        if ($incomingConfig !== []) {
            $moduleEnv->getCurrentScope()->setIncomingConfiguration($incomingConfig);
        }

        $state->loadingFiles[$moduleId] = true;

        try {
            $compiledCss = $syntax === Syntax::CSS
                ? $this->plainCssRenderer->render($moduleAst, $moduleEnv)
                : $this->dispatcher->compile($moduleAst, $moduleEnv);
        } finally {
            unset($state->loadingFiles[$moduleId]);
        }

        if ($namespace === '*') {
            foreach (array_keys($moduleEnv->getCurrentScope()->getVariables()) as $name) {
                if (NameNormalizer::isPrivate($name)) {
                    continue;
                }

                if ($moduleEnv->getCurrentScope()->isImportedMember($name)) {
                    continue;
                }

                $env->getCurrentScope()->setVariableLocal($name, $moduleEnv->getCurrentScope()->getVariable($name));
                $env->getCurrentScope()->markImportedMember($name);
                $env->getCurrentScope()->trackImportedVariable($name, $moduleEnv->getCurrentScope(), $name);
            }

            foreach ($moduleEnv->getCurrentScope()->getMixins() as $name => $mixin) {
                if (NameNormalizer::isPrivate($name)) {
                    continue;
                }

                if ($moduleEnv->getCurrentScope()->isImportedMember($name)) {
                    continue;
                }

                $env->getCurrentScope()->setMixin($name, $mixin);
                $env->getCurrentScope()->markImportedMember($name);
            }

            foreach ($moduleEnv->getCurrentScope()->getFunctions() as $name => $function) {
                if (NameNormalizer::isPrivate($name)) {
                    continue;
                }

                if ($moduleEnv->getCurrentScope()->isImportedMember($name)) {
                    continue;
                }

                $env->getCurrentScope()->setFunction($name, $function);
                $env->getCurrentScope()->markImportedMember($name);
            }

            return;
        }

        if ($node->configuration === []) {
            $state->registerModule($namespace, $moduleId, $moduleEnv->getCurrentScope(), $compiledCss);
        } else {
            $state->addByNamespace($namespace, new LoadedModule($moduleId, $moduleEnv->getCurrentScope(), $compiledCss));
        }

        $env->getCurrentScope()->addModule($namespace, $moduleEnv->getCurrentScope());
        $env->getGlobalScope()->addModule($namespace, $moduleEnv->getCurrentScope());
    }

    public function handleForward(ForwardNode $node, Environment $env): string
    {
        $path  = $node->path;
        $state = $this->state();

        $resolvedConfiguration = $this->resolveForwardConfiguration($node, $env);

        $incomingConfig = $this->collectIncomingConfiguration($env);

        if ($incomingConfig !== []) {
            $prefixedIncoming = $this->stripPrefixFromConfiguration($incomingConfig, $node->prefix);

            foreach ($prefixedIncoming as $name => $value) {
                if (! isset($resolvedConfiguration[$name])) {
                    $resolvedConfiguration[$name] = $value;
                }
            }
        }

        if ($this->importEvaluationDepth() > 0) {
            $resolvedConfiguration = $this->resolveImportForwardConfiguration($node, $env, $resolvedConfiguration);

            $prefix = $node->prefix ?? '';

            if ($prefix === '' && $resolvedConfiguration === []) {
                $defaultKey = $this->forwardCacheKey($path, [], $env);

                if (! isset($state->forwardedModules[$defaultKey])) {
                    $resolvedConfiguration = array_filter(
                        $env->getCurrentScope()->getVariables(),
                        static fn(mixed $value): bool => $value instanceof AstNode,
                    );
                }
            }
        }

        $forwardKey = $this->forwardCacheKey($path, $resolvedConfiguration, $env);

        if ($resolvedConfiguration === [] && ! isset($state->forwardedModules[$forwardKey])) {
            foreach (array_keys($state->forwardedModules) as $cachedKey) {
                if (str_starts_with($cachedKey, $path . "\0")) {
                    $forwardKey = $cachedKey;

                    break;
                }
            }
        }

        if (! isset($state->forwardedModules[$forwardKey])) {
            $moduleData = $this->loadAndEvaluateModule($path, $resolvedConfiguration);

            $state->forwardedModules[$forwardKey] = $moduleData;

            $namespace = $this->deriveNamespaceFromUsePath($path);
            $moduleId  = $this->loader->load($path)['path'];

            if ($node->configuration !== [] || ! $state->hasNamespace($namespace)) {
                $state->addByNamespace($namespace, new LoadedModule($moduleId, $moduleData['scope'], $moduleData['css']));
            }
        }

        $moduleData = $state->forwardedModules[$forwardKey];

        $this->mergeScopeExports(
            $moduleData['scope'],
            $env->getCurrentScope(),
            $node->prefix,
            $node->visibility,
            $node->members,
            $this->importEvaluationDepth() > 0,
        );

        return $forwardKey;
    }

    public function deriveNamespaceFromUsePath(string $path): string
    {
        return ltrim(pathinfo($path, PATHINFO_FILENAME), '_');
    }

    /**
     * @return array{type: 'css', raw: string}|array{type: 'sass', path: string}
     */
    public function resolveImport(string $import): array
    {
        $raw = trim($import);

        if ($raw === '') {
            return ['type' => 'css', 'raw' => $raw];
        }

        if ($this->isCssImportRaw($raw)) {
            return ['type' => 'css', 'raw' => $raw];
        }

        if ($raw[0] === '"' || $raw[0] === "'") {
            $quote        = $raw[0];
            $length       = strlen($raw);
            $closingIndex = 1;

            while ($closingIndex < $length && $raw[$closingIndex] !== $quote) {
                $closingIndex++;
            }

            if ($closingIndex >= $length) {
                return ['type' => 'css', 'raw' => $raw];
            }

            $path  = substr($raw, 1, $closingIndex - 1);
            $media = trim(substr($raw, $closingIndex + 1));

            if ($media !== '' || $this->isCssImportPath($path)) {
                return ['type' => 'css', 'raw' => '"' . $path . '"' . ($media !== '' ? ' ' . $media : '')];
            }

            return ['type' => 'sass', 'path' => $path];
        }

        if (str_contains($raw, ' ')) {
            return ['type' => 'css', 'raw' => $raw];
        }

        if ($this->isCssImportPath($raw)) {
            return ['type' => 'css', 'raw' => $raw];
        }

        return ['type' => 'sass', 'path' => $raw];
    }

    /**
     * @param array<string, AstNode> $configuration
     * @param array<string, AstNode> $initialVariables
     * @return array{scope: Scope, css: string}
     */
    public function loadAndEvaluateModule(
        string $path,
        array $configuration = [],
        bool $fromImport = false,
        bool $compileCss = true,
        array $initialVariables = [],
    ): array {
        $file = $this->loader->load($path, $fromImport);

        $this->loader->addPath(dirname($file['path']));

        $syntax       = Syntax::fromPath($file['path'], $file['content']);
        $moduleSource = $this->ctx->normalizerPipeline->process($file['content'], $syntax);
        $moduleAst    = $this->parser->parse($moduleSource);
        $moduleEnv    = new Environment();

        foreach ($initialVariables as $name => $value) {
            $moduleEnv->getCurrentScope()->setVariableLocal($name, $value);
        }

        foreach ($configuration as $name => $value) {
            $moduleEnv->getCurrentScope()->setVariable($name, $value);
        }

        $moduleEnv->getCurrentScope()->setIncomingConfiguration($configuration);

        if ($fromImport) {
            $resolvedPath = $file['path'];

            if (isset($this->ctx->moduleState->loadingFiles[$resolvedPath])) {
                throw ModuleResolutionException::circularDependency($resolvedPath);
            }

            $this->ctx->moduleState->loadingFiles[$resolvedPath] = true;
            $this->ctx->moduleState->importEvaluationDepth++;
        }

        try {
            $css = $compileCss
                ? ($syntax === Syntax::CSS
                    ? $this->plainCssRenderer->render($moduleAst, $moduleEnv)
                    : $this->dispatcher->compile($moduleAst, $moduleEnv))
                : '';
        } finally {
            if ($fromImport) {
                $this->ctx->moduleState->importEvaluationDepth--;
                unset($this->ctx->moduleState->loadingFiles[$file['path']]);
            }
        }

        return [
            'scope' => $moduleEnv->getCurrentScope(),
            'css'   => $css,
        ];
    }

    /**
     * @param callable(array<int, AstNode>): string $compileChildren
     */
    public function inlineImportedFile(string $path, callable $compileChildren): ?string
    {
        $file = $this->loader->load($path, true);

        $this->loader->addPath(dirname($file['path']));

        $resolvedPath = $file['path'];
        $syntax       = Syntax::fromPath($resolvedPath, $file['content']);

        if ($syntax === Syntax::CSS) {
            return null;
        }

        $state = $this->state();

        if (isset($state->loadingFiles[$resolvedPath])) {
            throw ModuleResolutionException::circularDependency($resolvedPath);
        }

        $ast = $this->parser->parse($this->ctx->normalizerPipeline->process($file['content'], $syntax));

        $state->loadingFiles[$resolvedPath] = true;
        $state->importEvaluationDepth++;

        try {
            return $compileChildren($ast->children);
        } finally {
            $state->importEvaluationDepth--;

            unset($state->loadingFiles[$resolvedPath]);
        }
    }

    /**
     * @param array<string, mixed> $variables
     * @return array<string, AstNode>
     */
    public function extractAstVariables(array $variables): array
    {
        return array_filter($variables, static fn(mixed $value): bool => $value instanceof AstNode);
    }

    public function qualifyImportedCssWithParentSelector(string $css, string $parentSelector): string
    {
        $lines     = explode("\n", $css);
        $result    = [];
        $depth     = 0;
        $wrapDepth = 0;

        foreach ($lines as $line) {
            $trimmed = trim($line);

            if ($depth === 0 && $wrapDepth === 0) {
                if (
                    $trimmed !== ''
                    && str_ends_with($trimmed, '{')
                    && ! str_starts_with($trimmed, '@')
                ) {
                    $selector = trim(substr($trimmed, 0, -1));

                    if ($selector !== '') {
                        $leadingSpaces = strlen($line) - strlen(ltrim($line, ' '));
                        $indent        = str_repeat(' ', $leadingSpaces);

                        if (str_contains($selector, '&')) {
                            $result[]  = $indent . $parentSelector . ' {';
                            $result[]  = '  ' . $line;
                            $wrapDepth = 1;
                        } else {
                            $combined = $this->selector->combineNestedSelectorWithParent($selector, $parentSelector);
                            $result[] = $indent . $combined . ' {';
                        }
                    } else {
                        $result[] = $line;
                    }
                } else {
                    $result[] = $line;
                }
            } elseif ($wrapDepth > 0) {
                $result[] = $trimmed === '' ? $line : '  ' . $line;
            } else {
                $result[] = $line;
            }

            $depth += substr_count($line, '{') - substr_count($line, '}');

            if ($wrapDepth > 0 && $depth === 0) {
                $result[]  = '}';
                $wrapDepth = 0;
            }
        }

        return implode("\n", $result);
    }

    /**
     * @return array<string, AstNode>
     */
    public function resolveForwardConfiguration(ForwardNode $node, Environment $env): array
    {
        if ($node->configuration === []) {
            return [];
        }

        $resolved = [];

        foreach ($node->configuration as $name => $entry) {
            $valueNode    = $entry['value'];
            $isDefault    = $entry['default'];
            $currentValue = $isDefault ? $env->getCurrentScope()->getAstVariable($name) : null;

            if ($currentValue !== null && ! $currentValue instanceof NullNode) {
                $resolved[$name] = $currentValue;

                continue;
            }

            $resolved[$name] = $this->evaluation->evaluateValue($valueNode, $env);
        }

        return $resolved;
    }

    /**
     * @param array<string, AstNode> $resolvedConfiguration
     * @return array<string, AstNode>
     */
    public function resolveImportForwardConfiguration(
        ForwardNode $node,
        Environment $env,
        array $resolvedConfiguration,
    ): array {
        $prefix = $node->prefix ?? '';

        if ($prefix === '') {
            return $resolvedConfiguration;
        }

        foreach (array_keys($env->getCurrentScope()->getVariables()) as $name) {
            if (! str_starts_with($name, $prefix)) {
                continue;
            }

            $forwardedName = substr($name, strlen($prefix));

            if ($forwardedName === '' || array_key_exists($forwardedName, $resolvedConfiguration)) {
                continue;
            }

            $value = $env->getCurrentScope()->getAstVariable($name);

            if ($value !== null) {
                $resolvedConfiguration[$forwardedName] = $value;
            }
        }

        return $resolvedConfiguration;
    }

    /**
     * @param array<string, AstNode> $configuration
     */
    public function forwardCacheKey(string $path, array $configuration, Environment $env): string
    {
        if ($configuration === []) {
            return $path;
        }

        $normalized = array_map(
            fn(AstNode $value): string => $this->evaluation->format($value, $env),
            $configuration,
        );

        ksort($normalized);

        return $path . "\0" . serialize($normalized);
    }

    public function importEvaluationDepth(): int
    {
        return $this->ctx->moduleState->importEvaluationDepth;
    }

    /**
     * @param array<int, string> $members
     */
    public function mergeScopeExports(
        Scope $from,
        Scope $to,
        ?string $prefix = null,
        ?string $visibility = null,
        array $members = [],
        bool $trackImportedVariables = false,
    ): void {
        $normalizedMembers = $this->normalizeForwardMembers($members);

        foreach (array_keys($from->getVariables()) as $name) {
            if (! $this->shouldForwardMember($name, true, $visibility, $normalizedMembers, $prefix)) {
                continue;
            }

            if ($from->isImportedMember($name)) {
                continue;
            }

            $exportedName = $this->prefixExportName($name, $prefix);

            $to->setVariableLocal($exportedName, $from->getVariable($name));
            $to->trackForwardedVariable($exportedName, $from, $name);

            if ($trackImportedVariables) {
                $to->trackImportedVariable($exportedName, $from, $name);
            }
        }

        foreach ($from->getMixins() as $name => $mixin) {
            if (! $this->shouldForwardMember($name, false, $visibility, $normalizedMembers, $prefix)) {
                continue;
            }

            if ($from->isImportedMember($name)) {
                continue;
            }

            $to->setMixin(
                $this->prefixExportName($name, $prefix),
                $mixin,
            );
        }

        foreach ($from->getFunctions() as $name => $function) {
            if (! $this->shouldForwardMember($name, false, $visibility, $normalizedMembers, $prefix)) {
                continue;
            }

            if ($from->isImportedMember($name)) {
                continue;
            }

            $to->setFunction(
                $this->prefixExportName($name, $prefix),
                $function,
            );
        }
    }

    private function prefixExportName(string $name, ?string $prefix): string
    {
        if ($prefix === null || $prefix === '') {
            return $name;
        }

        return $prefix . $name;
    }

    /**
     * @param array<int, string> $members
     * @return array{variables: array<int, string>, others: array<int, string>}
     */
    private function normalizeForwardMembers(array $members): array
    {
        $variables = [];
        $others    = [];

        foreach ($members as $member) {
            if ($member === '') {
                continue;
            }

            if ($member[0] === '$') {
                $variables[] = NameNormalizer::normalize(substr($member, 1));

                continue;
            }

            $others[] = NameNormalizer::normalize($member);
        }

        return [
            'variables' => array_unique($variables),
            'others'    => array_unique($others),
        ];
    }

    /**
     * @param array{variables: array<int, string>, others: array<int, string>} $members
     */
    private function shouldForwardMember(
        string $name,
        bool $isVariable,
        ?string $visibility,
        array $members,
        ?string $prefix = null,
    ): bool {
        if ($visibility !== 'show' && $visibility !== 'hide') {
            return true;
        }

        $effectiveName  = $this->prefixExportName($name, $prefix);
        $normalizedName = NameNormalizer::normalize($effectiveName);
        $set            = $isVariable ? $members['variables'] : $members['others'];
        $contains       = in_array($normalizedName, $set, true);

        return $visibility === 'show' ? $contains : ! $contains;
    }

    /** @return array<string, AstNode> */
    private function collectIncomingConfiguration(Environment $env): array
    {
        $scope = $env->getCurrentScope();

        while ($scope !== null) {
            $config = $scope->getIncomingConfiguration();

            if ($config !== []) {
                return $config;
            }

            $scope = $scope->getParent();
        }

        return [];
    }

    /**
     * @param array<string, AstNode> $configuration
     * @return array<string, AstNode>
     */
    private function stripPrefixFromConfiguration(array $configuration, ?string $prefix): array
    {
        if ($prefix === null || $prefix === '') {
            return $configuration;
        }

        $stripped = [];

        foreach ($configuration as $name => $value) {
            if (str_starts_with($name, $prefix)) {
                $stripped[substr($name, strlen($prefix))] = $value;
            } else {
                $stripped[$name] = $value;
            }
        }

        return $stripped;
    }

    private function isCssImportRaw(string $raw): bool
    {
        $lower = strtolower($raw);

        if (str_starts_with($lower, 'url(')) {
            return true;
        }

        if (str_starts_with($lower, 'http:' . '//') || str_starts_with($lower, 'https://')) {
            return true;
        }

        return false;
    }

    private function isCssImportPath(string $path): bool
    {
        $lower = strtolower(trim($path));

        if ($lower === '') {
            return false;
        }

        if (str_starts_with($lower, 'http:' . '//') || str_starts_with($lower, 'https://')) {
            return true;
        }

        return str_ends_with($lower, '.css');
    }

    /** @return array<string, true> */
    private function collectDefaultVariableNames(RootNode $ast, int $depth = 0): array
    {
        if ($depth > 10) {
            return [];
        }

        $defaults = [];

        foreach ($ast->children as $node) {
            if ($node instanceof VariableDeclarationNode && $node->default) {
                $defaults[NameNormalizer::normalize($node->name)] = true;
            }

            if ($node instanceof ForwardNode) {
                foreach ($node->configuration as $name => $entry) {
                    if ($entry['default']) {
                        $defaults[NameNormalizer::normalize($name)] = true;
                    }
                }

                $forwardedDefaults = $this->collectDefaultVariableNamesFromForward($node, $depth + 1);
                $defaults          = array_merge($defaults, $forwardedDefaults);
            }

            if ($node instanceof ImportNode) {
                foreach ($node->imports as $import) {
                    $resolved = $this->resolveImport($import);

                    if ($resolved['type'] !== 'sass') {
                        continue;
                    }

                    /** @var array{type: 'sass', path: string} $resolved */
                    $importedDefaults = $this->collectDefaultVariableNamesFromImport(
                        $resolved['path'],
                        $depth + 1,
                    );
                    $defaults = array_merge($defaults, $importedDefaults);
                }
            }
        }

        return $defaults;
    }

    /** @return array<string, true> */
    private function collectDefaultVariableNamesFromForward(ForwardNode $node, int $depth): array
    {
        try {
            $file = $this->loader->load($node->path);

            $this->loader->addPath(dirname($file['path']));

            $syntax       = Syntax::fromPath($file['path'], $file['content']);
            $moduleSource = $this->ctx->normalizerPipeline->process($file['content'], $syntax);
            $moduleAst    = $this->parser->parse($moduleSource);

            return $this->collectDefaultVariableNames($moduleAst, $depth);
        } catch (Throwable) {
            return [];
        }
    }

    /** @return array<string, true> */
    private function collectDefaultVariableNamesFromImport(string $path, int $depth): array
    {
        try {
            $file = $this->loader->load($path, true);

            $this->loader->addPath(dirname($file['path']));

            $syntax       = Syntax::fromPath($file['path'], $file['content']);
            $moduleSource = $this->ctx->normalizerPipeline->process($file['content'], $syntax);
            $moduleAst    = $this->parser->parse($moduleSource);

            return $this->collectDefaultVariableNames($moduleAst, $depth);
        } catch (Throwable) {
            return [];
        }
    }
}
