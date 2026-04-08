<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\Iris\Converters\ModelConverter;
use Bugo\Iris\Converters\SpaceConverter;
use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\LiteralSerializer;
use Bugo\Iris\SpaceRouter;
use Bugo\SCSS\Builtins\Color\ColorAstParser;
use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;

final readonly class ColorRuntime
{
    public ColorAstParser $colorAstParser;

    public ColorFunctionArgumentList $arguments;

    public ColorChannelSchema $channelSchema;

    public ColorArgumentParser $argumentParser;

    public ColorValueFormatter $formatter;

    public CssColorFunctionConverter $cssColorFunctionConverter;

    public function __construct(
        public ColorModuleContext $context,
        public SpaceConverter $spaceConverter = new SpaceConverter(),
        public SpaceRouter $spaceRouter = new SpaceRouter(),
        public ModelConverter $modelConverter = new ModelConverter(),
        public LiteralParser $literalParser = new LiteralParser(),
        public LiteralSerializer $literalSerializer = new LiteralSerializer(),
    ) {
        $this->colorAstParser            = new ColorAstParser();
        $this->arguments                 = new ColorFunctionArgumentList();
        $this->channelSchema             = new ColorChannelSchema();
        $this->argumentParser            = new ColorArgumentParser($this->spaceConverter, $this->context);
        $this->formatter                 = new ColorValueFormatter($this->spaceConverter);
        $this->cssColorFunctionConverter = new CssColorFunctionConverter(
            $this->spaceConverter,
            $this->spaceRouter,
            $this->arguments,
        );
    }
}
