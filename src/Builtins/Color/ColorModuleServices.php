<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;
use Bugo\SCSS\Builtins\Color\Operations\ColorChannelInspector;
use Bugo\SCSS\Builtins\Color\Operations\ColorConstructorEvaluator;
use Bugo\SCSS\Builtins\Color\Operations\ColorFunctionEvaluator;

final class ColorModuleServices
{
    public function __construct(
        public ColorSpaceConverter $spaceConverter,
        public ColorChannelInspector $channelInspector,
        public ColorFunctionEvaluator $functions,
        public ColorConstructorEvaluator $constructors,
    ) {}
}
