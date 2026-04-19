<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\Environment;

interface AstValueFormatterInterface
{
    public function format(AstNode $node, Environment $env): string;
}
