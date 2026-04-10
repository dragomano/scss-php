<?php

declare(strict_types=1);

namespace Bugo\SCSS\Runtime;

use LogicException;

use function array_key_exists;
use function array_map;

final class VariableRegistry
{
    /** @var array<string, VariableEntry> */
    private array $entries = [];

    public function set(string $name, mixed $value, int $line = 1): void
    {
        $this->entries[$name] = new VariableEntry($value, $line);
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->entries);
    }

    public function get(string $name): mixed
    {
        if (! $this->has($name)) {
            throw new LogicException('Variable "' . $name . '" is not defined in registry.');
        }

        return $this->entries[$name]->value;
    }

    public function getLine(string $name): int
    {
        return $this->entries[$name]->line ?? 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        return array_map(
            static fn(VariableEntry $entry): mixed => $entry->value,
            $this->entries,
        );
    }
}
