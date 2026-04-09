<?php

declare(strict_types=1);

namespace Bugo\SCSS\States;

use Bugo\SCSS\Runtime\Scope;

final readonly class LoadedModule
{
    public function __construct(
        public string $id,
        public Scope  $scope,
        public string $css,
    ) {}
}
