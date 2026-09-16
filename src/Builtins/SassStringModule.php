<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Utils\StringHelper;
use Bugo\SCSS\Values\AstValueInspector;

use function array_map;
use function array_slice;
use function count;
use function floor;
use function implode;
use function is_infinite;
use function is_int;
use function is_nan;
use function ord;
use function strlen;
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

    /**
     * @var array<string, array<int, string>>
     */
    private const PARAMETER_NAMES = [
        'index'         => ['string', 'substring'],
        'insert'        => ['string', 'insert', 'index'],
        'length'        => ['string'],
        'quote'         => ['string'],
        'slice'         => ['string', 'start-at', 'end-at'],
        'split'         => ['string', 'separator', 'limit'],
        'to-lower-case' => ['string'],
        'to-upper-case' => ['string'],
        'unique-id'     => [],
        'unquote'       => ['string'],
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
            if ($named !== []) {
                $positional = $this->mergeNamedArguments($positional, $named, self::PARAMETER_NAMES[$name] ?? []);
            }

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

        $characters = $this->characters($string);
        $length     = count($characters);

        $firstString  = $positional[0] ?? null;
        $secondString = $positional[1] ?? null;
        $quoted       = AstValueInspector::isQuotedString($firstString)
            || AstValueInspector::isQuotedString($secondString);

        if ($index === 0) {
            $offset = 0;
        } elseif ($index > 0) {
            $offset = $index - 1;
        } else {
            $offset = $length + $index + 1;
            if ($offset < 0) {
                $offset = 0;
            }
        }

        if ($offset > $length) {
            $offset = $length;
        }

        return new StringNode(
            implode('', array_slice($characters, 0, $offset)) . $insert
                . implode('', array_slice($characters, $offset)),
            $quoted,
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

        $index = $this->findCharacters($this->characters($string), $this->characters($substring));

        return $index === null ? $this->nullNode() : new NumberNode($index + 1);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function length(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'length', $positional);

        $value = $this->requireStringArg($positional, 0, 'string.length');

        return new NumberNode(count($this->characters($value)));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function quote(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'quote', $positional);

        return new StringNode(
            $this->stripQuotes($this->requireStringArg($positional, 0, 'string.quote')),
            true,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function slice(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'slice', $positional);

        $string     = $this->requireStringArg($positional, 0, 'string.slice');
        $start      = $this->requireIntegerArg($positional, 1, 'string.slice');
        $end        = isset($positional[2]) ? $this->requireIntegerArg($positional, 2, 'string.slice') : -1;
        $characters = $this->characters($string);
        $length     = count($characters);

        $quoted      = AstValueInspector::isQuotedString($positional[0] ?? null);
        $startOffset = $start > 0 ? $start - 1 : ($start === 0 ? 0 : $length + $start);

        if ($startOffset < 0) {
            $startOffset = 0;
        }

        $endOffset = $end >= 0 ? $end : $length + $end + 1;

        if ($endOffset < 0) {
            $endOffset = 0;
        }

        if ($endOffset < $startOffset) {
            return new StringNode('', $quoted);
        }

        return new StringNode(
            implode('', array_slice($characters, $startOffset, $endOffset - $startOffset)),
            $quoted,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function split(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'split', $positional);

        $inputNode = $positional[0] ?? null;
        $quoted    = AstValueInspector::isQuotedString($inputNode);
        $string    = $this->requireStringArg($positional, 0, 'string.split');
        $separator = $this->requireStringArg($positional, 1, 'string.split');
        $limitNode = $named['limit'] ?? ($positional[2] ?? null);

        if ($limitNode !== null && (! ($limitNode instanceof NumberNode) || ! is_int($limitNode->value))) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('string.split'),
                'an integer argument',
            );
        }

        $limit = $limitNode instanceof NumberNode ? $limitNode->value : null;

        if ($limit !== null && $limit < 1) {
            throw BuiltinArgumentException::mustBePositiveInteger(
                $this->builtinCallReference('string.split'),
                'limit',
            );
        }

        if ($string === '') {
            return new ListNode([], 'comma', true);
        }

        $remaining = $this->characters($string);

        $parts = [];
        $limit ??= count($remaining);
        $splits = 0;

        while ($splits <= $limit && $remaining !== []) {
            if ($splits === $limit) {
                $parts[] = implode('', $remaining);

                break;
            }

            if ($separator === '') {
                $parts[]   = $remaining[0];
                $remaining = array_slice($remaining, 1);

                $splits++;

                continue;
            }

            $separatorCharacters = $this->characters($separator);

            $index = $this->findCharacters($remaining, $separatorCharacters);

            if ($index === null) {
                $parts[] = implode('', $remaining);

                break;
            }

            $parts[]   = implode('', array_slice($remaining, 0, $index));
            $remaining = array_slice($remaining, $index + count($separatorCharacters));

            $splits++;
        }

        return new ListNode(
            array_map(fn(string $part): AstNode => new StringNode($part, $quoted), $parts),
            'comma',
            true,
        );
    }

    /**
     * @return list<string>
     */
    private function characters(string $value): array
    {
        $characters = [];
        $length     = strlen($value);

        for ($index = 0; $index < $length;) {
            $byte  = ord($value[$index]);
            $width = $byte >= 0xF0 ? 4 : ($byte >= 0xE0 ? 3 : ($byte >= 0xC0 ? 2 : 1));

            $characters[] = substr($value, $index, $width);

            $index += $width;
        }

        return $characters;
    }

    /**
     * @param list<string> $haystack
     * @param list<string> $needle
     */
    private function findCharacters(array $haystack, array $needle): ?int
    {
        if ($needle === []) {
            return 0;
        }

        foreach ($haystack as $index => $_) {
            if (array_slice($haystack, $index, count($needle)) === $needle) {
                return $index;
            }
        }

        return null;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function toLowerCase(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'to-lower-case', $positional);

        $quoted = AstValueInspector::isQuotedString($positional[0] ?? null);

        return new StringNode(
            strtolower($this->requireStringArg($positional, 0, 'string.to-lower-case')),
            $quoted,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function toUpperCase(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $this->warnAboutDeprecatedStringFunction($context, 'to-upper-case', $positional);

        $quoted = AstValueInspector::isQuotedString($positional[0] ?? null);

        return new StringNode(
            strtoupper($this->requireStringArg($positional, 0, 'string.to-upper-case')),
            $quoted,
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

        return new StringNode($this->requireStringArg($positional, 0, 'string.unquote'));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function warnAboutDeprecatedStringFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional = [],
    ): void {
        if (! $this->isGlobalBuiltinCall()) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            $this->deprecatedStringSuggestion($name, $positional),
            'string.' . $name,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deprecatedStringSuggestion(string $name, array $positional): string
    {
        $rawArguments = $this->activeBuiltinContext?->rawArguments;
        $arguments = $rawArguments !== null
            ? $this->rawPositionalArguments($rawArguments)
            : $positional;

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
        if ($value instanceof StringNode || $value instanceof NumberNode) {
            return (string) $value;
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
     * @param array<int, AstNode> $positional
     */
    private function requireStringArg(array $positional, int $index, string $context): string
    {
        if (! isset($positional[$index])) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext($context),
                'required argument',
            );
        }

        $value = $positional[$index];

        if ($value instanceof StringNode) {
            return $value->value;
        }

        if ($value instanceof NumberNode) {
            return (string) $value;
        }

        throw new MissingFunctionArgumentsException(
            $this->builtinErrorContext($context),
            'a string argument',
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function requireIntegerArg(array $positional, int $index, string $context): int
    {
        $node = $positional[$index] ?? null;

        if ($node instanceof NumberNode) {
            $value = $node->value;

            if (is_int($value)) {
                return $value;
            }

            if (! is_nan($value) && ! is_infinite($value) && floor($value) === $value) {
                return (int) $value;
            }
        }

        throw new MissingFunctionArgumentsException(
            $this->builtinErrorContext($context),
            'an integer argument',
        );
    }

    private function stripQuotes(string $value): string
    {
        return StringHelper::unquote($value);
    }
}
