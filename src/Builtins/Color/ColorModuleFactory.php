<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;
use Bugo\SCSS\Builtins\Color\Conversion\DartColorMath;
use Bugo\SCSS\Builtins\Color\Operations\ColorChannelInspector;
use Bugo\SCSS\Builtins\Color\Operations\ColorConstructorEvaluator;
use Bugo\SCSS\Builtins\Color\Operations\ColorFunctionEvaluator;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Builtins\Color\Support\LegacyColorMath;

final class ColorModuleFactory
{
    public function create(
        ColorModuleContext $context,
        ?ColorModuleComponents $components = null,
    ): ColorModuleServices {
        $components ??= ColorModuleComponents::defaults();

        $runtime = new ColorRuntime(
            context: $context,
            spaceConverter: $components->spaceConverter,
            spaceRouter: $components->spaceRouter,
            modelConverter: $components->modelConverter,
            literalParser: $components->literalParser,
            literalSerializer: $components->literalSerializer,
        );

        $converter        = new ColorNodeConverter($runtime);
        $dartMath         = new DartColorMath();
        $spaceConverter   = new ColorSpaceConverter($runtime, $converter, dartMath: $dartMath);
        $channelInspector = new ColorChannelInspector($runtime, $converter, $spaceConverter);

        $functions = new ColorFunctionEvaluator(
            $runtime,
            $components->legacyManipulator,
            $converter,
            new LegacyColorMath($components->spaceConverter),
            $dartMath,
        );

        $spaceConverter->functionEvaluator = $functions;

        return new ColorModuleServices(
            spaceConverter: $spaceConverter,
            channelInspector: $channelInspector,
            functions: $functions,
            constructors: new ColorConstructorEvaluator(
                $runtime->argumentParser,
                $converter,
                $context,
            ),
        );
    }
}
