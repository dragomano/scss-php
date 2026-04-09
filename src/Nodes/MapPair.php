<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

final readonly class MapPair
{
    public function __construct(public AstNode $key, public AstNode $value) {}
}
