<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\RootNode;

interface ParserInterface
{
    public function setTrackSourceLocations(bool $track): void;

    public function parse(string $source): RootNode;

    public function parseInlineExpression(string $expr): AstNode;
}
