<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\LiteralSerializer;
use Bugo\Iris\SpaceRouter;
use Bugo\SCSS\Builtins\Color\Adapters\IrisColorManipulator;
use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;
use Bugo\SCSS\Builtins\Color\Adapters\IrisLiteralAdapter;
use Bugo\SCSS\Contracts\Color\ColorBundleInterface;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Contracts\Color\ColorLiteralInterface;
use Bugo\SCSS\Contracts\Color\ColorManipulatorInterface;

final readonly class ColorBundleAdapter implements ColorBundleInterface
{
    public function __construct(
        private SpaceConverter $spaceConverter = new SpaceConverter(),
        private SpaceRouter $spaceRouter = new SpaceRouter(),
        private LiteralSerializer $literalSerializer = new LiteralSerializer(),
        private LiteralParser $literalConverter = new LiteralParser(),
        private IrisColorManipulator $manipulator = new IrisColorManipulator()
    ) {}

    public function getConverter(): ColorConverterInterface
    {
        return new IrisConverterAdapter($this->spaceConverter, $this->spaceRouter);
    }

    public function getLiteral(): ColorLiteralInterface
    {
        return new IrisLiteralAdapter($this->literalSerializer, $this->literalConverter);
    }

    public function getManipulator(): ColorManipulatorInterface
    {
        return $this->manipulator;
    }
}
