<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\Iris\Manipulators\LegacyManipulator;
use Bugo\Iris\Manipulators\PerceptualManipulator;
use Bugo\Iris\Manipulators\SrgbManipulator;
use Bugo\Iris\Operations\ColorMixResolver;

final readonly class ColorManipulators
{
    public function __construct(
        public LegacyManipulator $legacy = new LegacyManipulator(),
        public PerceptualManipulator $perceptual = new PerceptualManipulator(),
        public SrgbManipulator $srgb = new SrgbManipulator(),
        public ColorMixResolver $mixResolver = new ColorMixResolver(),
    ) {}
}
