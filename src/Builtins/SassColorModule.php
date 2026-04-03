<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins;

use Bugo\SCSS\Builtins\Color\Ast\ColorAstParser;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstReader;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstWriter;
use Bugo\SCSS\Builtins\Color\ColorBundleAdapter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceInterop;
use Bugo\SCSS\Builtins\Color\Conversion\CssColorFunctionConverter;
use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\Builtins\Color\Operations\ColorChannelReader;
use Bugo\SCSS\Builtins\Color\Operations\ColorConstructorFunctions;
use Bugo\SCSS\Builtins\Color\Operations\ColorFunctionEvaluator;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorChannelSchema;
use Bugo\SCSS\Builtins\Color\Support\ColorValueFormatter;
use Bugo\SCSS\Contracts\Color\ColorBundleInterface;
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
        'color-channel' => 'channel',
        'rgba'          => 'legacy-rgba',
    ];

    private readonly ColorArgumentParser $argumentParser;

    private readonly ColorNodeConverter $converter;

    private readonly ColorSpaceInterop $spaceInterop;

    private readonly ColorChannelReader $channelReader;

    private readonly ColorFunctionEvaluator $functions;

    private readonly ColorConstructorFunctions $constructors;

    private readonly ColorValueFormatter $formatter;

    private readonly ColorAstWriter $astWriter;

    private readonly ColorAstReader $astReader;

    public function __construct(
        private readonly HexColorConverter $hexColorConverter = new HexColorConverter(),
        ColorBundleInterface $bundle = new ColorBundleAdapter(),
    ) {
        $errorCtx = fn(string $f): string => $this->builtinErrorContext($f);
        $isGlobal = fn(): bool => $this->isGlobalBuiltinCall();
        $warn     = function (?BuiltinCallContext $ctx, string $s, bool $multipleSuggestions = false): void {
            $this->warnAboutDeprecatedBuiltinFunction($ctx, $s, 'color', $multipleSuggestions);
        };

        $converter    = $bundle->getConverter();
        $literal      = $bundle->getLiteral();
        $manipulator  = $bundle->getManipulator();

        $this->argumentParser = new ColorArgumentParser($converter, $errorCtx);
        $this->formatter      = new ColorValueFormatter($converter);
        $this->astWriter      = new ColorAstWriter($converter, $literal);

        $cssColorFunctionConverter = new CssColorFunctionConverter($converter);

        $this->converter = new ColorNodeConverter(
            $this->hexColorConverter,
            $cssColorFunctionConverter,
            $converter,
            $literal,
            new ColorAstParser(),
            $errorCtx,
        );

        $this->astReader = new ColorAstReader(
            $this->argumentParser,
            $this->converter,
            $this->hexColorConverter,
            $converter,
        );

        $this->spaceInterop = new ColorSpaceInterop(
            $this->argumentParser,
            $this->converter,
            $this->astWriter,
            $this->astReader,
            $this->hexColorConverter,
            $converter,
            new ColorChannelSchema(),
            $errorCtx,
        );

        $this->channelReader = new ColorChannelReader(
            $this->argumentParser,
            $this->converter,
            $this->formatter,
            $converter,
            new ColorChannelSchema(),
            $errorCtx,
            $isGlobal,
            $warn,
        );

        $this->functions = new ColorFunctionEvaluator(
            $this->argumentParser,
            $this->converter,
            $this->astWriter,
            $this->astReader,
            $manipulator,
            $this->spaceInterop,
            $this->formatter,
            $converter,
            $warn,
        );

        $this->constructors = new ColorConstructorFunctions(
            $this->argumentParser,
            $this->converter,
            $this->astWriter,
            $errorCtx,
        );
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
                'alpha', 'opacity'       => $this->channelReader->channelAlpha($positional, $name, $context),
                'blackness',
                'blue',
                'green',
                'hue',
                'lightness',
                'red',
                'saturation',
                'whiteness'              => $this->deprecatedChannelFunction($name, $positional, $context),
                'change', 'change-color' => $this->functions->changeColor($positional, $named),
                'channel'                => $this->channelReader->channel($positional, $named),
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
                'is-in-gamut'            => $this->channelReader->isInGamut($positional),
                'is-legacy'              => $this->channelReader->isLegacy($positional),
                'is-missing'             => $this->channelReader->isMissing($positional),
                'is-powerless'           => $this->channelReader->isPowerless($positional, $named),
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
                'space'                  => $this->channelReader->space($positional),
                'to-gamut'               => $this->spaceInterop->toGamut($positional, $named),
                'to-space'               => $this->spaceInterop->toSpace($positional),
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
            'red', 'green', 'blue' => $this->channelReader->deprecatedChannelValue(
                $positional,
                'rgb',
                $name,
                $name,
                $context,
            ),
            'hue', 'saturation', 'lightness' => $this->channelReader->deprecatedChannelValue(
                $positional,
                'hsl',
                $name,
                $name,
                $context,
            ),
            'whiteness', 'blackness' => $this->channelReader->deprecatedChannelValue(
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
        $direction = match ($name) {
            'opacify',
            'fade-in'  => 1,
            'transparentize',
            'fade-out' => -1,
            default    => throw new UnknownSassFunctionException('color', $name),
        };

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
