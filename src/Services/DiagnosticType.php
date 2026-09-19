<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

enum DiagnosticType: string
{
    case DEBUG   = 'debug';
    case WARNING = 'warning';
    case ERROR   = 'error';

    public function directive(): string
    {
        return $this === self::WARNING ? 'warn' : $this->value;
    }
}
