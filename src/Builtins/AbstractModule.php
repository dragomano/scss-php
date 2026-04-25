<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\DeprecatedBuiltinFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Values\AstValueDescriber;
use Bugo\SCSS\Values\ValueFactory;

use function array_combine;
use function array_map;
use function array_merge;
use function str_contains;
use function strtolower;

abstract class AbstractModule implements ModuleInterface
{
    protected ?string $activeBuiltinDisplayName = null;

    protected ?BuiltinCallContext $activeBuiltinContext = null;

    abstract public function getName(): string;

    /**
     * @return array<int, string>
     */
    abstract public function getFunctions(): array;

    /**
     * @return array<string, string>
     */
    abstract public function getGlobalAliases(): array;

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    abstract public function call(
        string $name,
        array $positional,
        array $named,
        ?BuiltinCallContext $context,
    ): AstNode;

    /**
     * @return array<string, AstNode>
     */
    public function getVariables(): array
    {
        return [];
    }

    protected function boolNode(bool $value): BooleanNode
    {
        return $this->valueFactory()->createBooleanNode($value);
    }

    protected function nullNode(): NullNode
    {
        return $this->valueFactory()->createNullNode();
    }

    /**
     * @param array<int, string> $passthrough
     * @param array<string, string> $mapped
     * @return array<string, string>
     */
    protected function globalAliases(array $passthrough = [], array $mapped = []): array
    {
        if ($passthrough === []) {
            return $mapped;
        }

        $identity = array_combine($passthrough, $passthrough);

        return array_merge($identity, $mapped);
    }

    protected function valueFactory(): ValueFactory
    {
        /** @var ValueFactory|null $valueFactory */
        static $valueFactory = null;

        if (! $valueFactory instanceof ValueFactory) {
            $valueFactory = new ValueFactory();
        }

        return $valueFactory;
    }

    protected function beginBuiltinCall(string $name, ?BuiltinCallContext $context): ?string
    {
        $previousDisplayName = $this->activeBuiltinDisplayName;

        $this->activeBuiltinContext = $context;

        if ($context === null || $context->builtinDisplayName === null) {
            $this->activeBuiltinDisplayName = strtolower($name);
        } else {
            $this->activeBuiltinDisplayName = strtolower($context->builtinDisplayName);
        }

        return $previousDisplayName;
    }

    protected function endBuiltinCall(?string $previousDisplayName): void
    {
        $this->activeBuiltinDisplayName = $previousDisplayName;
        $this->activeBuiltinContext     = null;
    }

    protected function builtinErrorContext(string $fallback): string
    {
        $displayName = $this->activeBuiltinDisplayName ?? strtolower($fallback);

        if (str_contains($displayName, '.')) {
            return $displayName;
        }

        return $displayName . '() (' . $this->getName() . ' module)';
    }

    protected function builtinCallReference(string $fallback): string
    {
        $context = $this->builtinErrorContext($fallback);

        if (str_contains($context, '()')) {
            return $context;
        }

        return $context . '()';
    }

    protected function isGlobalBuiltinCall(): bool
    {
        return ! str_contains($this->activeBuiltinDisplayName ?? '', '.');
    }

    protected function deprecatedBuiltinFunctionReference(string $fallback): string
    {
        $displayName = $this->activeBuiltinDisplayName ?? strtolower($fallback);

        return $displayName . '()';
    }

    protected function describeBuiltinValue(AstNode $value): string
    {
        return AstValueDescriber::describe($value);
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, string>
     */
    protected function describeBuiltinArguments(array $arguments): array
    {
        return array_map($this->describeBuiltinValue(...), $arguments);
    }

    /**
     * @param array<int, AstNode|NamedArgumentNode> $rawArguments
     * @return array<int, AstNode>
     */
    protected function rawPositionalArguments(array $rawArguments): array
    {
        $positional = [];

        foreach ($rawArguments as $argument) {
            if ($argument instanceof NamedArgumentNode) {
                continue;
            }

            $positional[] = $argument;
        }

        return $positional;
    }

    protected function warnAboutDeprecatedBuiltinFunction(
        ?BuiltinCallContext $context,
        string $suggestions,
        string $fallback,
        bool $multipleSuggestions = false,
    ): void {
        if ($context === null) {
            return;
        }

        $context->warn((new DeprecatedBuiltinFunctionException(
            $this->deprecatedBuiltinFunctionReference($fallback),
            $suggestions,
            $multipleSuggestions,
        ))->getMessage());
    }

    protected function warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
        ?BuiltinCallContext $context,
        string $suggestion,
        string $fallback,
    ): void {
        $this->warnAboutDeprecatedBuiltinFunction($context, $suggestion, $fallback);
    }
}
