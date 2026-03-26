<?php

declare(strict_types=1);

namespace Bugo\SCSS\Contracts\Color;

interface ColorBundleInterface
{
    public function getConverter(): ColorConverterInterface;

    public function getLiteral(): ColorLiteralInterface;

    public function getManipulator(): ColorManipulatorInterface;
}
