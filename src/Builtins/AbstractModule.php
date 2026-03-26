<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\DeprecatedBuiltinFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Values\ValueFactory;

use function array_combine;
use function array_map;
use function array_merge;
use function implode;
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
        ?BuiltinCallContext $context
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
        if ($value instanceof VariableReferenceNode) {
            return '$' . $value->name;
        }

        if ($value instanceof NumberNode) {
            return "$value->value" . ($value->unit ?? '');
        }

        if ($value instanceof StringNode) {
            return $value->quoted ? '"' . $value->value . '"' : $value->value;
        }

        if ($value instanceof ListNode) {
            $items = $this->describeBuiltinArguments($value->items);
            $glue  = $value->separator === 'comma' ? ', ' : ' ';
            $text  = implode($glue, $items);

            if ($value->bracketed) {
                return '[' . $text . ']';
            }

            return $text;
        }

        if ($value instanceof MapNode) {
            $pairs = [];

            foreach ($value->pairs as $pair) {
                $pairs[] = $this->describeBuiltinValue($pair['key']) . ': ' . $this->describeBuiltinValue($pair['value']);
            }

            return '(' . implode(', ', $pairs) . ')';
        }

        if ($value instanceof ColorNode) {
            return $value->value;
        }

        return '';
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, string>
     */
    protected function describeBuiltinArguments(array $arguments): array
    {
        return array_map($this->describeBuiltinValue(...), $arguments);
    }

    protected function warnAboutDeprecatedBuiltinFunction(
        ?BuiltinCallContext $context,
        string $suggestions,
        string $fallback,
        bool $multipleSuggestions = false
    ): void {
        if ($context === null) {
            return;
        }

        $context->warn((new DeprecatedBuiltinFunctionException(
            $this->deprecatedBuiltinFunctionReference($fallback),
            $suggestions,
            $multipleSuggestions
        ))->getMessage());
    }

    protected function warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
        ?BuiltinCallContext $context,
        string $suggestion,
        string $fallback
    ): void {
        $this->warnAboutDeprecatedBuiltinFunction($context, $suggestion, $fallback);
    }
}
