<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use ArrayIterator;
use IteratorAggregate;
use Traversable;

use function array_key_exists;

/**
 * @implements IteratorAggregate<string, CallableDefinition>
 */
final class CallableDefinitionMap implements IteratorAggregate
{
    /** @var array<string, CallableDefinition> */
    private array $items = [];

    public function set(string $name, CallableDefinition $definition): void
    {
        $this->items[$name] = $definition;
    }

    public function get(string $name): ?CallableDefinition
    {
        return $this->items[$name] ?? null;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->items);
    }

    /**
     * @return Traversable<string, CallableDefinition>
     */
    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->items);
    }
}
