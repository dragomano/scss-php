<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\BuiltinArgumentException;
use Bugo\SCSS\Exceptions\InvalidArgumentTypeException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownListSeparatorException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Utils\AstValueComparator;
use Bugo\SCSS\Values\AstValueSuggestionDescriber;

use function abs;
use function array_map;
use function array_merge;
use function count;
use function get_debug_type;
use function implode;
use function in_array;
use function is_int;
use function round;

final class SassListModule extends AbstractModule
{
    private const FUNCTIONS = [
        'append',
        'index',
        'is-bracketed',
        'join',
        'length',
        'nth',
        'separator',
        'set-nth',
        'slash',
        'zip',
    ];

    private const GLOBAL_FUNCTIONS = [
        'append',
        'index',
        'is-bracketed',
        'join',
        'length',
        'nth',
        'set-nth',
        'zip',
    ];

    private const GLOBAL_ALIASES = [
        'list-separator' => 'separator',
        'list-slash'     => 'slash',
    ];

    public function getName(): string
    {
        return 'list';
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
                'append'       => $this->append($positional, $named, $context),
                'index'        => $this->index($positional, $context),
                'is-bracketed' => $this->isBracketed($positional, $context),
                'join'         => $this->join($positional, $named, $context),
                'length'       => $this->length($positional, $context),
                'nth'          => $this->nth($positional, $context),
                'separator'    => $this->separator($positional, $context),
                'set-nth'      => $this->setNth($positional, $context),
                'slash'        => $this->slash($positional),
                'zip'          => $this->zip($positional, $context),
                default        => throw new UnknownSassFunctionException('list', $name),
            };
        } finally {
            $this->endBuiltinCall($previousDisplayName);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function append(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('list.append'),
                'list and value arguments',
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'append', $positional, $named);

        $list         = $this->asList($positional[0]);
        $separatorArg = $named['separator'] ?? ($positional[2] ?? new StringNode('auto'));
        $separator    = $this->resolveSeparator($separatorArg, count($list->items) === 0 ? 'space' : $list->separator);

        $items   = $list->items;
        $items[] = $positional[1];

        return new ListNode($items, $separator, $list->bracketed);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function index(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('list.index'),
                'list and value arguments',
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'index', $positional);

        $list   = $this->asList($positional[0]);
        $needle = $positional[1];

        foreach ($list->items as $index => $item) {
            if (AstValueComparator::equals($item, $needle)) {
                return new NumberNode($index + 1);
            }
        }

        return $this->nullNode();
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function isBracketed(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('list.is-bracketed'),
                'a list argument',
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'is-bracketed', $positional);

        return $this->boolNode($this->asList($positional[0])->bracketed);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function join(array $positional, array $named, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('list.join'),
                2,
                true,
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'join', $positional, $named);

        $first  = $this->asList($positional[0]);
        $second = $this->asList($positional[1]);

        $separatorArg = $named['separator'] ?? ($positional[2] ?? new StringNode('auto'));
        $separator    = $this->resolveSeparator($separatorArg, $this->autoJoinSeparator($first->separator, $second->separator));
        $bracketedArg = $named['bracketed'] ?? ($positional[3] ?? new StringNode('auto'));
        $bracketed    = $this->resolveBracketed($bracketedArg, $first->bracketed);

        return new ListNode(array_merge($first->items, $second->items), $separator, $bracketed);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function length(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('list.length'),
                'a list argument',
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'length', $positional);

        if ($positional[0] instanceof MapNode) {
            return new NumberNode(count($positional[0]->pairs));
        }

        $list = $this->asList($positional[0]);

        return new NumberNode(count($list->items));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function nth(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('list.nth'),
                'list and index arguments',
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'nth', $positional);

        $list   = $this->asList($positional[0]);
        $index  = $this->requireInteger($positional[1], $this->builtinCallReference('list.nth') . ' index');
        $offset = $this->resolveIndex($index, count($list->items), $this->builtinCallReference('list.nth'));

        return $list->items[$offset];
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function separator(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('list.separator'),
                'a list argument',
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'separator', $positional);

        $list = $this->asList($positional[0]);

        if ($list->items === []) {
            return new StringNode('space');
        }

        return new StringNode($list->separator);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function setNth(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 3) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('list.set-nth'),
                'list, index and value arguments',
            );
        }

        $this->warnAboutDeprecatedListFunction($context, 'set-nth', $positional);

        $list   = $this->asList($positional[0]);
        $index  = $this->requireInteger($positional[1], $this->builtinCallReference('list.set-nth') . ' index');
        $offset = $this->resolveIndex($index, count($list->items), $this->builtinCallReference('list.set-nth'));
        $items  = $list->items;

        $items[$offset] = $positional[2];

        return new ListNode($items, $list->separator, $list->bracketed);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function slash(array $positional): AstNode
    {
        if (count($positional) < 1) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('list.slash'),
                1,
                true,
            );
        }

        return new ListNode($positional, 'slash');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function zip(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            return new ListNode([], 'comma');
        }

        $this->warnAboutDeprecatedListFunction($context, 'zip', $positional);

        $lists = array_map($this->asList(...), $positional);
        $size  = count($lists[0]->items);

        foreach ($lists as $list) {
            if (count($list->items) < $size) {
                $size = count($list->items);
            }
        }

        $zipped = [];

        for ($i = 0; $i < $size; $i++) {
            $row = [];

            foreach ($lists as $list) {
                $row[] = $list->items[$i];
            }

            $zipped[] = new ListNode($row, 'space');
        }

        return new ListNode($zipped, 'comma');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function warnAboutDeprecatedListFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional,
        array $named = [],
    ): void {
        if (! $this->isGlobalBuiltinCall()) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            $this->deprecatedListSuggestion($name, $positional, $named),
            'list.' . $name,
        );
    }

    private function asList(AstNode $value): ListNode
    {
        if ($value instanceof ArgumentListNode) {
            return new ListNode($value->items, $value->separator, $value->bracketed);
        }

        if ($value instanceof ListNode) {
            return $value;
        }

        return new ListNode([$value], 'space');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function deprecatedListSuggestion(string $name, array $positional, array $named): string
    {
        $arguments = match ($name) {
            'append'    => $this->appendSuggestionArguments($positional, $named),
            'join'      => $this->joinSuggestionArguments($positional, $named),
            'separator' => [$this->describeValue($positional[0])],
            default     => $this->describeArguments($positional),
        };

        $functionName = $name === 'separator' ? 'list.separator' : 'list.' . $name;

        return $functionName . '(' . implode(', ', $arguments) . ')';
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     * @return array<int, string>
     */
    private function appendSuggestionArguments(array $positional, array $named): array
    {
        $arguments = $this->describeArguments($positional);

        if (isset($named['separator'])) {
            $arguments[] = '$separator: ' . $this->describeValue($named['separator']);
        }

        return $arguments;
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     * @return array<int, string>
     */
    private function joinSuggestionArguments(array $positional, array $named): array
    {
        $arguments = $this->describeArguments($positional);

        if (isset($named['separator'])) {
            $arguments[] = '$separator: ' . $this->describeValue($named['separator']);
        }

        if (isset($named['bracketed'])) {
            $arguments[] = '$bracketed: ' . $this->describeValue($named['bracketed']);
        }

        return $arguments;
    }

    /**
     * @param array<int, AstNode> $arguments
     * @return array<int, string>
     */
    private function describeArguments(array $arguments): array
    {
        return AstValueSuggestionDescriber::describeArguments($arguments);
    }

    private function describeValue(AstNode $value): string
    {
        return AstValueSuggestionDescriber::describe($value);
    }

    private function requireInteger(AstNode $value, string $context): int
    {
        if (! ($value instanceof NumberNode)) {
            throw new InvalidArgumentTypeException($context, 'an integer number', get_debug_type($value));
        }

        if (is_int($value->value)) {
            return $value->value;
        }

        $number         = $value->value;
        $nearestInteger = (int) round($number);

        if (abs($number - (float) $nearestInteger) < 0.00000000001) {
            return $nearestInteger;
        }

        throw new InvalidArgumentTypeException($context, 'an integer number', get_debug_type($value));
    }

    private function resolveIndex(int $index, int $count, string $context): int
    {
        if ($count < 1) {
            throw BuiltinArgumentException::cannotOperateOnEmpty($context, 'list');
        }

        if ($index === 0) {
            throw BuiltinArgumentException::mustNotBeZero($context, 'index');
        }

        $offset = $index > 0 ? $index - 1 : $count + $index;

        if ($offset < 0 || $offset >= $count) {
            throw BuiltinArgumentException::outOfRange($context, 'index', $index);
        }

        return $offset;
    }

    private function resolveSeparator(AstNode $separator, string $default): string
    {
        if (! ($separator instanceof StringNode)) {
            throw new InvalidArgumentTypeException(
                'list separator',
                'one of: auto, comma, space, slash',
                get_debug_type($separator),
            );
        }

        if ($separator->value === 'auto') {
            return $default;
        }

        if (! in_array($separator->value, ['comma', 'space', 'slash'], true)) {
            throw new UnknownListSeparatorException($separator->value);
        }

        return $separator->value;
    }

    private function resolveBracketed(AstNode $bracketed, bool $default): bool
    {
        if ($bracketed instanceof StringNode && $bracketed->value === 'auto') {
            return $default;
        }

        if ($bracketed instanceof BooleanNode) {
            return $bracketed->value;
        }

        return true;
    }

    private function autoJoinSeparator(string $first, string $second): string
    {
        if ($first === 'space' && $second === 'space') {
            return 'space';
        }

        return $first !== 'space' ? $first : $second;
    }
}
