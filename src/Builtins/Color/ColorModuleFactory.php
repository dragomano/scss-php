<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color;

use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;
use Bugo\SCSS\Builtins\Color\Operations\ColorChannelInspector;
use Bugo\SCSS\Builtins\Color\Operations\ColorConstructorEvaluator;
use Bugo\SCSS\Builtins\Color\Operations\ColorFunctionEvaluator;
use Bugo\SCSS\Builtins\Color\Support\ColorManipulators;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;

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

        $manipulators = new ColorManipulators(
            $components->legacyManipulator,
            $components->perceptualManipulator,
            $components->srgbManipulator,
        );

        $converter        = new ColorNodeConverter($runtime);
        $spaceConverter   = new ColorSpaceConverter($runtime, $converter);
        $channelInspector = new ColorChannelInspector($runtime, $converter);

        return new ColorModuleServices(
            spaceConverter: $spaceConverter,
            channelInspector: $channelInspector,
            functions: new ColorFunctionEvaluator(
                $runtime,
                $manipulators,
                $converter,
                $spaceConverter,
            ),
            constructors: new ColorConstructorEvaluator(
                $runtime->argumentParser,
                $converter,
                $context,
            ),
        );
    }
}
