<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Runtime\Scope;

final readonly class ResolvedModule
{
    public function __construct(
        public ?Scope $scope,
        public ?string $name,
    ) {}
}
