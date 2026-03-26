<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Utils\StringHelper;

use function array_map;
use function implode;
use function is_int;
use function strlen;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;

final class SassStringModule extends AbstractModule
{
    private const FUNCTIONS = [
        'index',
        'insert',
        'length',
        'quote',
        'slice',
        'split',
        'to-lower-case',
        'to-upper-case',
        'unique-id',
        'unquote',
    ];

    private const GLOBAL_FUNCTIONS = [
        'quote',
        'to-lower-case',
        'to-upper-case',
        'unique-id',
        'unquote',
    ];

    private const GLOBAL_ALIASES = [
        'str-index'  => 'index',
        'str-insert' => 'insert',
        'str-length' => 'length',
        'str-slice'  => 'slice',
    ];

    private int $uniqueId = 0;

    public function getName(): string
    {
        return 'string';
    }

    public function getFunctions(): array
    {
        return self::FUNCTIONS;
    }

    public function getGlobalAliases(): array
    {
        return $this->globalAliases(self::GLOBAL_FUNCTIONS, self::GLOBAL_ALIASES);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context = null): AstNode
    {
        $previousDisplayName = $this->beginBuiltinCall($name, $context);

        try {
            return match ($name) {
                'index'         => $this->index($positional, $context),
                'insert'        => $this->insert($positional, $context),
                'length'        => $this->length($positional, $context),
                'quote'         => $this->quote($positional, $context),
                'slice'         => $this->slice($positional, $context),
                'split'         => $this->split($positional, $named, $context),
                'to-lower-case' => $this->toLowerCase($positional, $context),
                'to-upper-case' => $this->toUpperCase($positional, $context),
                'unique-id'     => $this->uniqueId($context),
                'unquote'       => $this->unquote($positional, $context),
                default         => throw new UnknownSassFunctionException('string', $name),
            };
        } finally {
            $this->endBuiltinCall($previousDisplayName);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function insert(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'insert', $positional);

        $string = $this->requireStringArg($positional, 0, 'string.insert');
        $insert = $this->requireStringArg($positional, 1, 'string.insert');
        $index  = $this->requireIntegerArg($positional, 2, 'string.insert');
        $length = strlen($string);

        $firstString  = $positional[0] ?? null;
        $secondString = $positional[1] ?? null;
        $quoted       = ($firstString instanceof StringNode && $firstString->quoted)
            || ($secondString instanceof StringNode && $secondString->quoted);

        if ($index === 0) {
            throw BuiltinArgumentException::mustNotBeZero(
                $this->builtinCallReference('string.insert'),
                'index'
            );
        }

        $offset = $index > 0 ? $index - 1 : $length + $index + 1;
        if ($offset < 0) {
            $offset = 0;
        }

        if ($offset > $length) {
            $offset = $length;
        }

        return new StringNode(
            substr($string, 0, $offset) . $insert . substr($string, $offset),
            $quoted
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function index(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'index', $positional);

        $string    = $this->requireStringArg($positional, 0, 'string.index');
        $substring = $this->requireStringArg($positional, 1, 'string.index');

        $pos = strpos($string, $substring);

        if ($pos === false) {
            return $this->nullNode();
        }

        return new NumberNode($pos + 1);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function length(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'length', $positional);

        $value = $this->requireStringArg($positional, 0, 'string.length');

        return new NumberNode(strlen($value));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function quote(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'quote', $positional);

        return new StringNode(
            $this->stripQuotes($this->requireStringArg($positional, 0, 'string.quote')),
            true
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function slice(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'slice', $positional);

        $string = $this->requireStringArg($positional, 0, 'string.slice');
        $start  = $this->requireIntegerArg($positional, 1, 'string.slice');
        $end    = isset($positional[2]) ? $this->requireIntegerArg($positional, 2, 'string.slice') : -1;
        $length = strlen($string);

        $firstString = $positional[0] ?? null;
        $quoted      = $firstString instanceof StringNode && $firstString->quoted;
        $startOffset = $start > 0 ? $start - 1 : ($start === 0 ? 0 : $length + $start);

        if ($startOffset < 0) {
            $startOffset = 0;
        }

        $endOffset = $end > 0 ? $end : $length + $end + 1;

        if ($endOffset < 0) {
            $endOffset = 0;
        }

        if ($endOffset < $startOffset) {
            return new StringNode('', $quoted);
        }

        return new StringNode(substr($string, $startOffset, $endOffset - $startOffset), $quoted);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function split(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'split', $positional);

        $inputNode = $positional[0] ?? null;
        $quoted    = $inputNode instanceof StringNode && $inputNode->quoted;
        $string    = $this->requireStringArg($positional, 0, 'string.split');
        $separator = $this->requireStringArg($positional, 1, 'string.split');
        $limitNode = $named['limit'] ?? ($positional[2] ?? null);

        if ($limitNode !== null && (! ($limitNode instanceof NumberNode) || ! is_int($limitNode->value))) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('string.split'),
                'an integer argument'
            );
        }

        $limit = $limitNode instanceof NumberNode ? $limitNode->value : null;

        if ($limit !== null && $limit < 1) {
            throw BuiltinArgumentException::mustBePositiveInteger(
                $this->builtinCallReference('string.split'),
                'limit'
            );
        }

        if ($string === '') {
            return new ListNode([new StringNode('', $quoted)], 'comma', true);
        }

        $remaining = $string;

        $parts = [];
        $limit ??= strlen($string);
        $splits = 0;

        while ($splits <= $limit && strlen($remaining) > 0) {
            if ($splits === $limit) {
                $parts[] = $remaining;

                break;
            }

            if ($separator === '') {
                $parts[]   = substr($remaining, 0, 1);
                $remaining = substr($remaining, 1);

                $splits++;

                continue;
            }

            $index = strpos($remaining, $separator);

            if ($index === false) {
                $parts[] = $remaining;

                break;
            }

            $parts[]   = substr($remaining, 0, $index);
            $remaining = substr($remaining, $index + strlen($separator));

            $splits++;
        }

        return new ListNode(
            array_map(fn(string $part): AstNode => new StringNode($part, $quoted), $parts),
            'comma',
            true
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function toLowerCase(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'to-lower-case', $positional);

        $inputNode = $positional[0] ?? null;
        $quoted    = $inputNode instanceof StringNode && $inputNode->quoted;

        return new StringNode(
            strtolower($this->requireStringArg($positional, 0, 'string.to-lower-case')),
            $quoted
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function toUpperCase(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'to-upper-case', $positional);

        $inputNode = $positional[0] ?? null;
        $quoted    = $inputNode instanceof StringNode && $inputNode->quoted;

        return new StringNode(
            strtoupper($this->requireStringArg($positional, 0, 'string.to-upper-case')),
            $quoted
        );
    }

    private function uniqueId(?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'unique-id');

        $this->uniqueId++;

        return new StringNode('u' . $this->uniqueId);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function unquote(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'unquote', $positional);

        return new StringNode(
            StringHelper::unescapeQuotedContent(
                $this->stripQuotes($this->requireStringArg($positional, 0, 'string.unquote'))
            )
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function warnAboutDeprecatedStringFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional = []
    ): void {
        if (! $this->isGlobalBuiltinCall()) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            $this->deprecatedStringSuggestion($name, $positional),
            'string.' . $name
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deprecatedStringSuggestion(string $name, array $positional): string
    {
        $arguments = $this->rawArgumentsAvailable() ? $this->rawPositionalArguments() : $positional;

        return 'string.' . $name . '(' . implode(', ', $this->describeArguments($arguments)) . ')';
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, string>
     */
    private function describeArguments(array $arguments): array
    {
        return array_map($this->describeValue(...), $arguments);
    }

    private function describeValue(AstNode $value): string
    {
        if ($value instanceof StringNode) {
            return $value->quoted ? '"' . $value->value . '"' : $value->value;
        }

        if ($value instanceof NumberNode) {
            return "$value->value" . ($value->unit ?? '');
        }

        if ($value instanceof ListNode) {
            $items = $this->describeArguments($value->items);
            $glue  = $value->separator === 'comma' ? ', ' : ' ';
            $text  = implode($glue, $items);

            if ($value->bracketed) {
                return '[' . $text . ']';
            }

            return $text;
        }

        if ($value instanceof ColorNode) {
            return $value->value;
        }

        return '';
    }

    /**
     * @return array<int, AstNode>
     */
    private function rawPositionalArguments(): array
    {
        if ($this->activeBuiltinContext === null || $this->activeBuiltinContext->rawArguments === null) {
            return [];
        }

        $positional = [];

        foreach ($this->activeBuiltinContext->rawArguments as $argument) {
            if ($argument instanceof NamedArgumentNode) {
                continue;
            }

            $positional[] = $argument;
        }

        return $positional;
    }

    private function rawArgumentsAvailable(): bool
    {
        return $this->activeBuiltinContext !== null && $this->activeBuiltinContext->rawArguments !== null;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function requireStringArg(array $positional, int $index, string $context): string
    {
        if (! isset($positional[$index])) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'required argument'
            );
        }

        $value = $positional[$index];

        if ($value instanceof StringNode) {
            return $value->value;
        }

        if ($value instanceof NumberNode) {
            return "$value->value" . ($value->unit ?? '');
        }

        throw new MissingFunctionArgumentsException(
            $this->builtinErrorContext($context),
            'a string argument'
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function requireIntegerArg(array $positional, int $index, string $context): int
    {
        if (
            ! isset($positional[$index])
            || ! ($positional[$index] instanceof NumberNode)
            || ! is_int($positional[$index]->value)
        ) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'an integer argument'
            );
        }

        return $positional[$index]->value;
    }

    private function stripQuotes(string $value): string
    {
        return StringHelper::unquote($value);
    }
}
