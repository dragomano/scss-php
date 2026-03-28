<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Exceptions\InvalidSyntaxException;

use function pathinfo;
use function str_contains;
use function strtolower;

use const PATHINFO_EXTENSION;

enum Syntax: string
{
    case CSS  = 'css';
    case SASS = 'sass';
    case SCSS = 'scss';

    public static function fromPath(string $path, string $content = ''): self
    {
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if ($ext === self::CSS->value) {
            return self::CSS;
        }

        if ($ext === self::SASS->value) {
            return self::SASS;
        }

        if ($ext === self::SCSS->value) {
            return self::SCSS;
        }

        if ($ext === '') {
            // If no content provided, default to SCSS for backward compatibility
            if ($content === '') {
                return self::SCSS;
            }

            // Detect from content: if contains '{', assume SCSS, else SASS
            if (str_contains($content, '{')) {
                return self::SCSS;
            }

            return self::SASS;
        }

        throw InvalidSyntaxException::cannotDetectFromPath($path);
    }
}
