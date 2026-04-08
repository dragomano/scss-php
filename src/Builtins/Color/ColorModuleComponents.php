<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\Iris\Converters\ModelConverter;
use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\LiteralParser;
use Bugo\Iris\Manipulators\LegacyManipulator;
use Bugo\Iris\Manipulators\PerceptualManipulator;
use Bugo\Iris\Manipulators\SrgbManipulator;
use Bugo\Iris\Serializers\LiteralSerializer;
use Bugo\Iris\SpaceRouter;

final class ColorModuleComponents
{
    public function __construct(
        public SpaceConverter $spaceConverter,
        public SpaceRouter $spaceRouter,
        public ModelConverter $modelConverter,
        public LiteralParser $literalParser,
        public LiteralSerializer $literalSerializer,
        public LegacyManipulator $legacyManipulator,
        public PerceptualManipulator $perceptualManipulator,
        public SrgbManipulator $srgbManipulator,
    ) {}

    public static function defaults(): self
    {
        return new self(
            new SpaceConverter(),
            new SpaceRouter(),
            new ModelConverter(),
            new LiteralParser(),
            new LiteralSerializer(),
            new LegacyManipulator(),
            new PerceptualManipulator(),
            new SrgbManipulator(),
        );
    }
}
