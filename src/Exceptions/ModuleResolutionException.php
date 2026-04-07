<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

use function implode;

final class ModuleResolutionException extends SassException
{
    public static function importNotFound(string $path): self
    {
        return new self("File to import not found or unreadable: $path");
    }

    public static function notFound(string $module): self
    {
        return new self("Module '$module' not found.");
    }

    public static function unknownNamespace(string $value): self
    {
        return new self("Unknown module namespace '$value'.");
    }

    public static function callableNotFound(string $metaFunction, string $name, ?string $module = null): self
    {
        $message = $metaFunction . "() could not find '$name'";

        if ($module !== null) {
            $message .= " in module '$module'";
        }

        return new self($message . '.');
    }

    public static function circularDependency(string $path): self
    {
        return new self("Circular dependency detected: '$path' is already being loaded.");
    }

    public static function duplicateNamespace(string $namespace): self
    {
        return new self("Multiple @use rules with namespace '$namespace' in the same file.");
    }

    public static function nonConfigurableVariable(string $name, string $modulePath): self
    {
        return new self(implode(' ', [
            "This variable isn't declared with !default in the target stylesheet,",
            "so it can't be configured: \$$name (in module '$modulePath').",
        ]));
    }

    public static function builtInModuleConfiguration(string $module): self
    {
        return new self("Built-in module '$module' can't be configured.");
    }
}
