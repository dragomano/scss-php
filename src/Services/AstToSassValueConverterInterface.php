<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Values\SassValue;

interface AstToSassValueConverterInterface
{
    public function convert(AstNode $node, Environment $env): SassValue;
}
