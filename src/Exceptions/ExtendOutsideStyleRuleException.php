<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

final class ExtendOutsideStyleRuleException extends SassException
{
    public function __construct()
    {
        parent::__construct('@extend may only be used within style rules.');
    }
}
