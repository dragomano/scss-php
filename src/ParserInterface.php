<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Nodes\RootNode;

interface ParserInterface
{
    public function setTrackSourceLocations(bool $track): void;

    public function parse(string $source): RootNode;
}
