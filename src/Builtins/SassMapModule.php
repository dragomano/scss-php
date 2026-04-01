<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Exceptions\InvalidArgumentTypeException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Utils\AstValueComparator;

use function array_filter;
use function array_key_last;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function get_debug_type;
use function implode;
use function is_int;

/**
 * @phpstan-type MapPair array{key: AstNode, value: AstNode}
 * @psalm-type MapPair = array{key: AstNode, value: AstNode}
 */
final class SassMapModule extends AbstractModule
{
    private const FUNCTIONS = [
        'deep-merge',
        'deep-remove',
        'get',
        'has-key',
        'keys',
        'merge',
        'remove',
        'set',
        'values',
    ];

    private const GLOBAL_ALIASES = [
        'map-get'     => 'get',
        'map-has-key' => 'has-key',
        'map-keys'    => 'keys',
        'map-merge'   => 'merge',
        'map-remove'  => 'remove',
        'map-set'     => 'set',
        'map-values'  => 'values',
    ];

    public function getName(): string
    {
        return 'map';
    }

    public function getFunctions(): array
    {
        return self::FUNCTIONS;
    }

    public function getGlobalAliases(): array
    {
        return $this->globalAliases([], self::GLOBAL_ALIASES);
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
                'deep-merge'  => $this->deepMerge($positional),
                'deep-remove' => $this->deepRemove($positional),
                'get'         => $this->get($positional, $context),
                'has-key'     => $this->hasKey($positional, $context),
                'keys'        => $this->keys($positional, $context),
                'merge'       => $this->merge($positional, $context),
                'remove'      => $this->remove($positional, $context),
                'set'         => $this->set($positional, $context),
                'values'      => $this->values($positional, $context),
                default       => throw new UnknownSassFunctionException('map', $name),
            };
        } finally {
            $this->endBuiltinCall($previousDisplayName);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deepMerge(array $positional): AstNode
    {
        if (count($positional) < 2) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.deep-merge'),
                'two map arguments'
            );
        }

        return $this->deepMergeMaps(
            $this->asMap($positional[0], 'map.deep-merge'),
            $this->asMap($positional[1], 'map.deep-merge')
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deepRemove(array $positional): AstNode
    {
        if (count($positional) < 2) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.deep-remove'),
                'map and key path'
            );
        }

        return $this->removeNested(
            $this->asMap($positional[0], 'map.deep-remove'),
            array_slice($positional, 1)
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function get(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.get'),
                'map and key arguments'
            );
        }

        $this->warnAboutDeprecatedMapFunction($context, 'get', $positional);

        $current = $this->asMap($positional[0], 'map.get');
        $keys    = array_slice($positional, 1, -1);
        $lastKey = $positional[count($positional) - 1];

        foreach ($keys as $key) {
            $value = $this->findByKey($current, $key);

            if ($value === null) {
                return $this->nullNode();
            }

            if (! ($value instanceof MapNode)) {
                return $this->nullNode();
            }

            $current = $value;
        }

        return $this->findByKey($current, $lastKey) ?? $this->nullNode();
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function hasKey(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.has-key'),
                'map and key arguments'
            );
        }

        $this->warnAboutDeprecatedMapFunction($context, 'has-key', $positional);

        $value = $this->get($positional, null);

        return $this->boolNode(! ($value instanceof NullNode));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function keys(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.keys'),
                'a map argument'
            );
        }

        $this->warnAboutDeprecatedMapFunction($context, 'keys', $positional);

        $map = $this->asMap($positional[0], 'map.keys');

        return new ListNode(array_map(
            /** @param MapPair $pair */
            fn(array $pair): AstNode => $pair['key'],
            $map->pairs
        ), 'comma');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function merge(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 2) {
            throw MissingFunctionArgumentsException::count(
                $this->builtinErrorContext('map.merge'),
                2,
                true
            );
        }

        $this->warnAboutDeprecatedMapFunction($context, 'merge', $positional);

        $map1 = $this->asMap($positional[0], 'map.merge');

        if (count($positional) === 2) {
            return $this->mergeTwo($map1, $this->asMap($positional[1], 'map.merge'));
        }

        $args = array_slice($positional, 1);

        $lastIndex = array_key_last($args);

        if (! is_int($lastIndex)) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.merge'),
                'path keys and a map in variadic form'
            );
        }

        $map2 = $this->asMap($args[$lastIndex], 'map.merge');
        $path = array_slice($args, 0, -1);

        return $this->modifyNested($map1, $path, function (AstNode $existing) use ($map2): MapNode {
            if ($existing instanceof MapNode) {
                return $this->mergeTwo($existing, $map2);
            }

            return $map2;
        }, true);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function remove(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw MissingFunctionArgumentsException::required(
                $this->builtinErrorContext('map.remove'),
                'map'
            );
        }

        $this->warnAboutDeprecatedMapFunction($context, 'remove', $positional);

        $map = $this->asMap($positional[0], 'map.remove');

        if (count($positional) === 1) {
            return $map;
        }

        $removeKeys = array_slice($positional, 1);

        $result = array_filter(
            $map->pairs,
            /** @param MapPair $pair */
            function (array $pair) use ($removeKeys): bool {
                foreach ($removeKeys as $removeKey) {
                    if (AstValueComparator::equals($pair['key'], $removeKey)) {
                        return false;
                    }
                }

                return true;
            }
        );

        return new MapNode(array_values($result));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function set(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 3) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.set'),
                'map, key path and value'
            );
        }

        $this->warnAboutDeprecatedMapFunction($context, 'set', $positional);

        $map   = $this->asMap($positional[0], 'map.set');
        $value = $positional[count($positional) - 1];
        $path  = array_slice($positional, 1, -1);

        return $this->modifyNested($map, $path, fn(AstNode $existing) => $value, true);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function values(array $positional, ?BuiltinCallContext $context): AstNode
    {
        if (count($positional) < 1) {
            throw new MissingFunctionArgumentsException(
                $this->builtinErrorContext('map.values'),
                'a map argument'
            );
        }

        $this->warnAboutDeprecatedMapFunction($context, 'values', $positional);

        $map = $this->asMap($positional[0], 'map.values');

        return new ListNode(array_map(
            /** @param MapPair $pair */
            fn(array $pair): AstNode => $pair['value'],
            $map->pairs
        ), 'comma');
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function warnAboutDeprecatedMapFunction(
        ?BuiltinCallContext $context,
        string $name,
        array $positional
    ): void {
        if (! $this->isGlobalBuiltinCall()) {
            return;
        }

        $this->warnAboutDeprecatedBuiltinFunctionWithSingleSuggestion(
            $context,
            $this->deprecatedMapSuggestion($name, $positional),
            'map.' . $name
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deprecatedMapSuggestion(string $name, array $positional): string
    {
        $arguments = $positional;

        if ($this->hasRawArguments()) {
            $arguments = $this->rawPositionalArguments();
        }

        return 'map.' . $name . '(' . implode(', ', $this->describeBuiltinArguments($arguments)) . ')';
    }

    private function mergeTwo(MapNode $left, MapNode $right): MapNode
    {
        $result = [];

        foreach ($left->pairs as $pair) {
            $result[] = $pair;
        }

        foreach ($right->pairs as $rightPair) {
            $replaced = false;

            foreach ($result as $idx => $existing) {
                if (AstValueComparator::equals($existing['key'], $rightPair['key'])) {
                    $result[$idx] = $rightPair;
                    $replaced     = true;

                    break;
                }
            }

            if (! $replaced) {
                $result[] = $rightPair;
            }
        }

        return new MapNode($result);
    }

    private function deepMergeMaps(MapNode $left, MapNode $right): MapNode
    {
        $result = [];

        foreach ($left->pairs as $pair) {
            $result[] = $pair;
        }

        foreach ($right->pairs as $rightPair) {
            $matched = false;

            foreach ($result as $idx => $existing) {
                if (AstValueComparator::equals($existing['key'], $rightPair['key'])) {
                    if ($existing['value'] instanceof MapNode && $rightPair['value'] instanceof MapNode) {
                        $result[$idx] = [
                            'key'   => $existing['key'],
                            'value' => $this->deepMergeMaps($existing['value'], $rightPair['value']),
                        ];
                    } else {
                        $result[$idx] = $rightPair;
                    }

                    $matched = true;

                    break;
                }
            }

            if (! $matched) {
                $result[] = $rightPair;
            }
        }

        return new MapNode($result);
    }

    /**
     * @param array<int, AstNode> $path
     */
    private function removeNested(MapNode $map, array $path): MapNode
    {
        if ($path === []) {
            return $map;
        }

        $key   = $path[0];
        $tail  = array_slice($path, 1);
        $pairs = $map->pairs;

        foreach ($pairs as $idx => $pair) {
            if (! AstValueComparator::equals($pair['key'], $key)) {
                continue;
            }

            if ($tail === []) {
                unset($pairs[$idx]);

                return new MapNode(array_values($pairs));
            }

            if ($pair['value'] instanceof MapNode) {
                $pairs[$idx]['value'] = $this->removeNested($pair['value'], $tail);

                return new MapNode($pairs);
            }

            return $map;
        }

        return $map;
    }

    /**
     * @param array<int, AstNode> $path
     * @param callable(AstNode): AstNode $modify
     */
    private function modifyNested(MapNode $map, array $path, callable $modify, bool $addNesting): MapNode
    {
        if ($path === []) {
            $modified = $modify($map);

            return $modified instanceof MapNode ? $modified : $map;
        }

        $key   = $path[0];
        $tail  = array_slice($path, 1);
        $pairs = $map->pairs;

        foreach ($pairs as $idx => $pair) {
            if (AstValueComparator::equals($pair['key'], $key)) {
                if ($tail === []) {
                    $pairs[$idx]['value'] = $modify($pair['value']);

                    return new MapNode($pairs);
                }

                if ($pair['value'] instanceof MapNode) {
                    $pairs[$idx]['value'] = $this->modifyNested($pair['value'], $tail, $modify, $addNesting);

                    return new MapNode($pairs);
                }

                if (! $addNesting) {
                    return $map;
                }

                $pairs[$idx]['value'] = $this->modifyNested(new MapNode([]), $tail, $modify, true);

                return new MapNode($pairs);
            }
        }

        if (! $addNesting) {
            return $map;
        }

        if ($tail === []) {
            $pairs[] = ['key' => $key, 'value' => $modify(new MapNode([]))];

            return new MapNode($pairs);
        }

        $pairs[] = ['key' => $key, 'value' => $this->modifyNested(new MapNode([]), $tail, $modify, true)];

        return new MapNode($pairs);
    }

    private function findByKey(MapNode $map, AstNode $key): ?AstNode
    {
        foreach ($map->pairs as $pair) {
            if (AstValueComparator::equals($pair['key'], $key)) {
                return $pair['value'];
            }
        }

        return null;
    }

    private function asMap(AstNode $value, string $context): MapNode
    {
        if (! ($value instanceof MapNode)) {
            throw new InvalidArgumentTypeException(
                $this->builtinErrorContext($context),
                'map',
                get_debug_type($value)
            );
        }

        return $value;
    }
}
