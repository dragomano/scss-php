<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Builtins\Color\ColorModuleComponents;
use Bugo\SCSS\Builtins\Color\ColorModuleFactory;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;
use Bugo\SCSS\Builtins\Color\Operations\ColorChannelInspector;
use Bugo\SCSS\Builtins\Color\Operations\ColorConstructorEvaluator;
use Bugo\SCSS\Builtins\Color\Operations\ColorFunctionEvaluator;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\UnknownSassFunctionException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

final class SassColorModule extends AbstractModule
{
    private const FUNCTIONS = [
        'adjust',
        'alpha',
        'blackness',
        'blue',
        'change',
        'channel',
        'complement',
        'grayscale',
        'green',
        'hue',
        'hwb',
        'ie-hex-str',
        'invert',
        'is-in-gamut',
        'is-legacy',
        'is-missing',
        'is-powerless',
        'lightness',
        'mix',
        'opacity',
        'red',
        'same',
        'saturation',
        'scale',
        'space',
        'to-gamut',
        'to-space',
        'whiteness',
    ];

    private const GLOBAL_FUNCTIONS = [
        'adjust-color',
        'adjust-hue',
        'alpha',
        'blackness',
        'blue',
        'change-color',
        'color',
        'complement',
        'darken',
        'desaturate',
        'fade-in',
        'fade-out',
        'grayscale',
        'green',
        'hsl',
        'hsla',
        'hue',
        'hwb',
        'ie-hex-str',
        'invert',
        'lab',
        'lch',
        'lighten',
        'lightness',
        'mix',
        'oklab',
        'oklch',
        'opacify',
        'opacity',
        'red',
        'rgb',
        'rgba',
        'saturate',
        'saturation',
        'scale-color',
        'transparentize',
        'whiteness',
    ];

    private const GLOBAL_ALIASES = [
        'rgba' => 'legacy-rgba',
    ];

    private readonly ColorSpaceConverter $spaceConverter;

    private readonly ColorChannelInspector $channelInspector;

    private readonly ColorFunctionEvaluator $functions;

    private readonly ColorConstructorEvaluator $constructors;

    public function __construct(?ColorModuleComponents $components = null)
    {
        $context = new ColorModuleContext(
            errorCtx: fn(string $function): string => $this->builtinErrorContext($function),
            isGlobalBuiltinCall: fn(): bool => $this->isGlobalBuiltinCall(),
            warn: function (?BuiltinCallContext $callContext, string $message, bool $multipleSuggestions = false): void {
                $this->warnAboutDeprecatedBuiltinFunction($callContext, $message, 'color', $multipleSuggestions);
            },
        );

        $services = (new ColorModuleFactory())->create($context, $components);

        $this->spaceConverter   = $services->spaceConverter;
        $this->channelInspector = $services->channelInspector;
        $this->functions        = $services->functions;
        $this->constructors     = $services->constructors;
    }

    public function getName(): string
    {
        return 'color';
    }

    public function getFunctions(): array
    {
        return self::FUNCTIONS;
    }

    public function getGlobalAliases(): array
    {
        return $this->globalAliases(self::GLOBAL_FUNCTIONS, self::GLOBAL_ALIASES);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function call(string $name, array $positional, array $named, ?BuiltinCallContext $context = null): AstNode
    {
        $previousDisplayName = $this->beginBuiltinCall($name, $context);

        try {
            return match ($name) {
                'adjust-hue'             => $this->functions->adjustHue($positional, $context),
                'adjust', 'adjust-color' => $this->functions->adjustColor($positional, $named),
                'alpha', 'opacity'       => $this->channelInspector->channelAlpha($positional, $name, $context),
                'blackness',
                'blue',
                'green',
                'hue',
                'lightness',
                'red',
                'saturation',
                'whiteness'              => $this->deprecatedChannelFunction($name, $positional, $context),
                'change', 'change-color' => $this->functions->changeColor($positional, $named),
                'channel'                => $this->channelInspector->channel($positional, $named),
                'color'                  => $this->constructors->colorFunction($positional),
                'complement'             => $this->functions->complement($positional),
                'darken',
                'desaturate',
                'lighten',
                'saturate'               => $this->legacyChannelAdjustment($name, $positional, $context),
                'grayscale'              => $this->functions->grayscale($positional),
                'hsl'                    => $this->constructors->hslFunction($positional),
                'hsla'                   => $this->constructors->hslaFunction($positional),
                'hwb'                    => $this->constructors->hwbFunction($positional),
                'ie-hex-str'             => $this->constructors->ieHexStr($positional),
                'invert'                 => $this->functions->invert($positional, $named),
                'is-in-gamut'            => $this->channelInspector->isInGamut($positional),
                'is-legacy'              => $this->channelInspector->isLegacy($positional),
                'is-missing'             => $this->channelInspector->isMissing($positional),
                'is-powerless'           => $this->channelInspector->isPowerless($positional, $named),
                'lab'                    => $this->constructors->labFunction($positional),
                'lch'                    => $this->constructors->lchFunction($positional),
                'legacy-rgba'            => $this->constructors->legacyRgbaFunction($positional),
                'mix'                    => $this->functions->mix($positional, $named),
                'oklab'                  => $this->constructors->oklabFunction($positional),
                'oklch'                  => $this->constructors->oklchFunction($positional),
                'opacify',
                'fade-in',
                'transparentize',
                'fade-out'               => $this->legacyAlphaAdjustment($name, $positional, $context),
                'rgb'                    => $this->constructors->rgbFunction($positional),
                'rgba'                   => $this->constructors->rgbaFunction($positional),
                'same'                   => $this->functions->same($positional),
                'scale', 'scale-color'   => $this->functions->scaleColor($positional, $named),
                'space'                  => $this->channelInspector->space($positional),
                'to-gamut'               => $this->spaceConverter->toGamut($positional, $named),
                'to-space'               => $this->spaceConverter->toSpace($positional),
                default                  => throw new UnknownSassFunctionException('color', $name),
            };
        } finally {
            $this->endBuiltinCall($previousDisplayName);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function deprecatedChannelFunction(string $name, array $positional, ?BuiltinCallContext $context): AstNode
    {
        return match ($name) {
            'red', 'green', 'blue' => $this->channelInspector->deprecatedChannelValue(
                $positional,
                'rgb',
                $name,
                $name,
                $context,
            ),
            'hue', 'saturation', 'lightness' => $this->channelInspector->deprecatedChannelValue(
                $positional,
                'hsl',
                $name,
                $name,
                $context,
            ),
            'whiteness', 'blackness' => $this->channelInspector->deprecatedChannelValue(
                $positional,
                'hwb',
                $name,
                $name,
                $context,
            ),
            default => throw new UnknownSassFunctionException('color', $name),
        };
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function legacyAlphaAdjustment(string $name, array $positional, ?BuiltinCallContext $context): AstNode
    {
        $direction = $name === 'opacify' || $name === 'fade-in' ? 1 : -1;

        return $this->functions->adjustAlphaChannel($positional, $direction, $name, $context);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    private function legacyChannelAdjustment(string $name, array $positional, ?BuiltinCallContext $context): AstNode
    {
        [$channel, $direction, $allowCssDefer] = match ($name) {
            'lighten'    => ['lightness', 1, false],
            'darken'     => ['lightness', -1, false],
            'saturate'   => ['saturation', 1, true],
            'desaturate' => ['saturation', -1, true],
            default      => throw new UnknownSassFunctionException('color', $name),
        };

        return $this->functions->adjustColorChannelByPercent(
            $positional,
            $channel,
            $direction,
            $name,
            $allowCssDefer,
            $context,
        );
    }
}
