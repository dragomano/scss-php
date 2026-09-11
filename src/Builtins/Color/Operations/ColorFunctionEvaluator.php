<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\Iris\Manipulators\LegacyManipulator;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;
use Bugo\SCSS\Builtins\Color\Conversion\DartColorMath;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Builtins\Color\Support\LegacyColorMath;
use Bugo\SCSS\Builtins\Color\Support\RgbChannelScale;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnknownColorChannelException;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Bugo\SCSS\Values\AstValueInspector;

use function abs;
use function array_filter;
use function array_key_exists;
use function array_search;
use function array_values;
use function count;
use function explode;
use function implode;
use function in_array;
use function min;
use function sprintf;
use function strtolower;
use function trim;

final readonly class ColorFunctionEvaluator
{
    private const SPACE_CHANNELS = [
        'rgb'               => ['red', 'green', 'blue'],
        'hsl'               => ['hue', 'saturation', 'lightness'],
        'hwb'               => ['hue', 'whiteness', 'blackness'],
        'srgb'              => ['red', 'green', 'blue'],
        'srgb-linear'       => ['red', 'green', 'blue'],
        'display-p3'        => ['red', 'green', 'blue'],
        'display-p3-linear' => ['red', 'green', 'blue'],
        'a98-rgb'           => ['red', 'green', 'blue'],
        'prophoto-rgb'      => ['red', 'green', 'blue'],
        'rec2020'           => ['red', 'green', 'blue'],
        'xyz'               => ['x', 'y', 'z'],
        'xyz-d50'           => ['x', 'y', 'z'],
        'lab'               => ['lightness', 'a', 'b'],
        'oklab'             => ['lightness', 'a', 'b'],
        'lch'               => ['lightness', 'chroma', 'hue'],
        'oklch'             => ['lightness', 'chroma', 'hue'],
    ];

    private const SPACE_CHANNEL_TYPES = [
        'rgb'               => ['byte', 'byte', 'byte'],
        'hsl'               => ['hue', 'percent-sat', 'percent-flex'],
        'hwb'               => ['hue', 'percent-flex', 'percent-flex'],
        'srgb'              => ['unit01', 'unit01', 'unit01'],
        'srgb-linear'       => ['unit01', 'unit01', 'unit01'],
        'display-p3'        => ['unit01', 'unit01', 'unit01'],
        'display-p3-linear' => ['unit01', 'unit01', 'unit01'],
        'a98-rgb'           => ['unit01', 'unit01', 'unit01'],
        'prophoto-rgb'      => ['unit01', 'unit01', 'unit01'],
        'rec2020'           => ['unit01', 'unit01', 'unit01'],
        'xyz'               => ['unit01', 'unit01', 'unit01'],
        'xyz-d50'           => ['unit01', 'unit01', 'unit01'],
        'lab'               => ['percent', 'lab-ab', 'lab-ab'],
        'oklab'             => ['fraction01', 'oklab-ab', 'oklab-ab'],
        'lch'               => ['percent', 'lch-c', 'hue'],
        'oklch'             => ['fraction01', 'oklch-c', 'hue'],
    ];

    private const SPACE_CHANNEL_CATEGORIES = [
        'rgb'               => ['red', 'green', 'blue'],
        'srgb'              => ['red', 'green', 'blue'],
        'srgb-linear'       => ['red', 'green', 'blue'],
        'display-p3'        => ['red', 'green', 'blue'],
        'display-p3-linear' => ['red', 'green', 'blue'],
        'a98-rgb'           => ['red', 'green', 'blue'],
        'prophoto-rgb'      => ['red', 'green', 'blue'],
        'rec2020'           => ['red', 'green', 'blue'],
        'xyz'               => ['x', 'y', 'z'],
        'xyz-d50'           => ['x', 'y', 'z'],
        'hsl'               => ['hue', 'colorfulness', 'lightness'],
        'hwb'               => ['hue', null, null],
        'lab'               => ['lightness', null, null],
        'oklab'             => ['lightness', null, null],
        'lch'               => ['lightness', 'colorfulness', 'hue'],
        'oklch'             => ['lightness', 'colorfulness', 'hue'],
    ];

    private const CHANNEL_TYPE_RANGES = [
        'byte'         => [0.0, 255.0],
        'unit01'       => [0.0, 1.0],
        'percent'      => [0.0, 100.0],
        'percent-flex' => [0.0, 100.0],
        'percent-sat'  => [0.0, 100.0],
        'fraction01'   => [0.0, 1.0],
        'lab-ab'       => [-125.0, 125.0],
        'oklab-ab'     => [-0.4, 0.4],
        'lch-c'        => [0.0, 150.0],
        'oklch-c'      => [0.0, 0.4],
        'alpha'        => [0.0, 1.0],
    ];

    private const CHANNEL_TYPE_BOUNDS = [
        'byte'         => [0.0, 255.0],
        'unit01'       => null,
        'percent'      => [0.0, 100.0],
        'percent-sat'  => [0.0, INF],
        'percent-flex' => null,
        'fraction01'   => [0.0, 1.0],
        'lab-ab'       => null,
        'oklab-ab'     => null,
        'lch-c'        => [0.0, INF],
        'oklch-c'      => [0.0, INF],
        'alpha'        => [0.0, 1.0],
    ];

    public function __construct(
        private ColorRuntime $runtime,
        private LegacyManipulator $legacy,
        private ColorNodeConverter $converter,
        private ColorSpaceConverter $spaceInterop,
        private LegacyColorMath $legacyMath,
        private DartColorMath $dartMath,
    ) {}

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function scaleColor(array $positional, array $named): AstNode
    {
        return $this->applyColorOperation($positional, $named, 'scale-color', 'scale');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function adjustColor(array $positional, array $named, string $context = 'adjust-color'): AstNode
    {
        return $this->applyColorOperation($positional, $named, $context, 'adjust');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function changeColor(array $positional, array $named): AstNode
    {
        return $this->applyColorOperation($positional, $named, 'change-color', 'change');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function same(array $positional, array $named = []): BooleanNode
    {
        if (! isset($positional[0]) && isset($named['color1'])) {
            $positional[0] = $named['color1'];
        }

        if (! isset($positional[1]) && isset($named['color2'])) {
            $positional[1] = $named['color2'];
        }

        $left  = $this->converter->toRgb($this->runtime->argumentParser->requireColor($positional, 0, 'same'));
        $right = $this->converter->toRgb($this->runtime->argumentParser->requireColor($positional, 1, 'same'));

        $same = abs($left->rValue() - $right->rValue()) < 0.000001
            && abs($left->gValue() - $right->gValue()) < 0.000001
            && abs($left->bValue() - $right->bValue()) < 0.000001
            && abs($left->a - $right->a) < 0.000001;

        return new BooleanNode($same);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function invert(array $positional, array $named): AstNode
    {
        $color = $this->runtime->argumentParser->requireColorOrDefer($positional, 'invert');

        $space = strtolower(
            $this->runtime->argumentParser->asString(
                $named['space'] ?? new StringNode('rgb'),
                'invert',
            ),
        );

        $weightNode = $named['weight'] ?? ($positional[1] ?? new NumberNode(100));

        $weight = $weightNode instanceof NumberNode && $weightNode->unit !== '%'
            ? (float) $weightNode->value
            : $this->runtime->argumentParser->asPercentage($weightNode, 'invert');

        $p = $this->runtime->argumentParser->clamp($weight / 100.0, 1.0);

        if (isset($named['space'])) {
            $nativeSpace = $this->normalizeSpaceName($this->converter->detectNativeColorSpace($color));

            if ($space === 'hwb' && $nativeSpace === 'rgb') {
                [$channels, $alpha] = $this->colorChannelsInSpace($color, 'hwb');

                $inverted = [
                    $channels[0] === null ? null : $channels[0] + 180.0,
                    $channels[2],
                    $channels[1],
                ];

                foreach ($channels as $index => $channel) {
                    if ($channel !== null && $inverted[$index] !== null) {
                        $inverted[$index] = $channel * (1.0 - $p) + $inverted[$index] * $p;
                    }
                }

                $inverted = $this->dartConvert('hwb', 'rgb', $inverted);

                return $this->serializeModifiedColor($color, 'rgb', $inverted, $alpha);
            }

            if ($space !== $nativeSpace && in_array($space, [
                'a98-rgb',
                'display-p3',
                'lab',
                'lch',
                'oklab',
                'oklch',
                'prophoto-rgb',
                'rec2020',
                'xyz',
            ], true)) {
                [$channels, $alpha] = $this->colorChannelsInSpace($color, $space);

                $inverted = match ($space) {
                    'lch' => [
                        $channels[0] === null ? null : 100.0 - $channels[0],
                        $channels[1],
                        $channels[2] === null ? null : $this->runtime->spaceConverter->normalizeHue($channels[2] + 180.0),
                    ],
                    'oklch' => [
                        $channels[0] === null ? null : 1.0 - $channels[0],
                        $channels[1],
                        $channels[2] === null ? null : $this->runtime->spaceConverter->normalizeHue($channels[2] + 180.0),
                    ],
                    'lab' => [
                        $channels[0] === null ? 100.0 : 100.0 - $channels[0],
                        $channels[1] === null ? 0.0 : -$channels[1],
                        $channels[2] === null ? 0.0 : -$channels[2],
                    ],
                    'oklab' => [
                        $channels[0] === null ? 1.0 : 1.0 - $channels[0],
                        $channels[1] === null ? 0.0 : -$channels[1],
                        $channels[2] === null ? 0.0 : -$channels[2],
                    ],
                    default => array_map(
                        static fn(?float $channel): float => 1.0 - ($channel ?? 0.0),
                        $channels,
                    ),
                };

                foreach ($channels as $index => $channel) {
                    if ($channel !== null && $inverted[$index] !== null) {
                        $inverted[$index] = $channel * (1.0 - $p) + $inverted[$index] * $p;
                    }
                }

                $converted = $this->dartConvert($space, $nativeSpace, $inverted);

                if ($nativeSpace === 'lch' && $color instanceof FunctionNode) {
                    $native       = $this->nativeChannels($color, $nativeSpace);
                    $nativeChroma = $native['channels'][1] ?? null;

                    if ($nativeChroma !== null
                        && $this->dartMath->fuzzyEquals($nativeChroma, 0.0)
                    ) {
                        $converted[2] = null;
                    }
                }

                if ($nativeSpace === 'rgb') {
                    return $this->converter->buildRgbFunctionNode(
                        red: $converted[0] ?? 0.0,
                        green: $converted[1] ?? 0.0,
                        blue: $converted[2] ?? 0.0,
                        alpha: $alpha ?? 1.0,
                    );
                }

                return $this->serializeModifiedColor($color, $nativeSpace, $converted, $alpha);
            }

            if ($space === $nativeSpace) {
                $native   = $this->nativeChannels($color, $nativeSpace);
                $channels = $native['channels'];

                if ($nativeSpace === 'hsl' && $channels[0] === null && $color instanceof FunctionNode) {
                    [$rawChannels] = $this->converter->extractRawChannelsPublic($color);

                    $rawHue = $rawChannels[0] ?? null;

                    if ($rawHue instanceof NumberNode) {
                        $channels[0] = $this->channelValueFromNode($rawHue, 'hue');
                    }
                }

                if (($nativeSpace === 'lch' || $nativeSpace === 'oklch')
                    && $channels[2] === null
                    && $color instanceof FunctionNode
                ) {
                    [$rawChannels] = $this->converter->extractRawChannelsPublic($color);

                    $rawHue = $rawChannels[2] ?? null;

                    if ($rawHue instanceof NumberNode) {
                        $channels[2] = $this->channelValueFromNode($rawHue, 'hue');
                    }
                }

                $inverted = match ($nativeSpace) {
                    'hsl' => [
                        $channels[0] === null ? null : $channels[0] + 180.0,
                        $channels[1],
                        $channels[2] === null ? null : 100.0 - $channels[2],
                    ],
                    'hwb' => [
                        $channels[0] === null ? null : $channels[0] + 180.0,
                        $channels[2],
                        $channels[1],
                    ],
                    'lab' => [
                        $channels[0] === null ? null : 100.0 - $channels[0],
                        $channels[1] === null ? null : -$channels[1],
                        $channels[2] === null ? null : -$channels[2],
                    ],
                    'lch' => [
                        $channels[0] === null ? null : 100.0 - $channels[0],
                        $channels[1],
                        $channels[2] === null ? null : $channels[2] + 180.0,
                    ],
                    'oklab' => [
                        $channels[0] === null ? null : 1.0 - $channels[0],
                        $channels[1] === null ? null : -$channels[1],
                        $channels[2] === null ? null : -$channels[2],
                    ],
                    'oklch' => [
                        $channels[0] === null ? null : 1.0 - $channels[0],
                        $channels[1],
                        $channels[2] === null ? null : $channels[2] + 180.0,
                    ],
                    default => array_map(
                        static fn(?float $channel): ?float => $channel === null ? null : 1.0 - $channel,
                        $channels,
                    ),
                };

                foreach ($channels as $index => $channel) {
                    if ($channel !== null && $inverted[$index] !== null) {
                        $inverted[$index] = $channel * (1.0 - $p) + $inverted[$index] * $p;
                    }
                }

                return $this->serializeModifiedColor($color, $nativeSpace, $inverted, $native['alpha']);
            }
        }

        $rgb = $this->converter->toRgb($color);

        if ($space !== 'rgb' && $space !== 'srgb') {
            $channels  = $this->spaceInterop->rgbToWorkingSpaceChannels($rgb, $space);
            $inverted1 = 1.0 - $channels[0];
            $inverted2 = 1.0 - $channels[1];
            $inverted3 = 1.0 - $channels[2];

            $mixed1 = $this->runtime->spaceConverter->mixChannel($channels[0], $inverted1, 1.0 - $p);
            $mixed2 = $this->runtime->spaceConverter->mixChannel($channels[1], $inverted2, 1.0 - $p);
            $mixed3 = $this->runtime->spaceConverter->mixChannel($channels[2], $inverted3, 1.0 - $p);

            return $this->converter->serializeRgbResult(
                $this->spaceInterop->workingSpaceChannelsToRgb($space, [$mixed1, $mixed2, $mixed3], $rgb->a),
            );
        }

        $invertedRgb = $this->legacy->invert(RgbChannelScale::toNormalized($rgb), $p);

        if ($space === 'rgb'
            && ! $this->converter->isLegacyColor($color)
            && $this->normalizeSpaceName($this->converter->detectNativeColorSpace($color)) === 'lch'
        ) {
            $lch = $this->runtime->spaceConverter->rgbToLch($invertedRgb);

            return $this->buildOutOfRangeFunctionalNode(
                'lch',
                [$lch->l, $lch->c, $lch->h],
                $invertedRgb->a,
            );
        }

        $legacyHsl   = $this->extractLegacyHsl($color);

        if ($legacyHsl !== null && $legacyHsl['origin'] !== 'rgb') {
            return $this->emitLegacyHsl(
                $this->legacyMath->rgbToHsl(RgbChannelScale::toByte($invertedRgb)),
                $legacyHsl['origin'],
            );
        }

        return $this->converter->serializeRgbResult(RgbChannelScale::toByte($invertedRgb));
    }

    public function extractNativeOrConvertedOklchColor(AstNode $color): OklchColor
    {
        return $this->converter->extractOklch($color, 'color');
    }

    /** @param array<int, AstNode> $positional */
    public function adjustHue(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $color   = $this->runtime->argumentParser->requireColorOrDefer($positional, 'adjust-hue');
        $degrees = $this->runtime->argumentParser->asHueAngle($positional[1] ?? null, 'adjust-hue');

        if ($context !== null) {
            $this->runtime->context->warn(
                $context,
                $this->formatColorAdjustHint($color, 'hue', $this->runtime->formatter->formatDegrees($degrees)),
            );
        }

        if ($this->converter->isLegacyColor($color)) {
            return $this->emitModifiedLegacyColor(
                $color,
                fn(array $channels): array => $this->legacyMath->shiftChannel($channels, 'h', $degrees),
            );
        }

        return $this->adjustColor([$color], ['hue' => new NumberNode($degrees)], 'adjust-hue');
    }

    /** @param array<int, AstNode> $positional */
    public function adjustAlphaChannel(
        array $positional,
        int $direction,
        string $context,
        ?BuiltinCallContext $callContext,
    ): AstNode {
        $color  = $this->runtime->argumentParser->requireColor($positional, 0, $context);
        $amount = $this->runtime->argumentParser->asNumber($positional[1] ?? null, $context) * (float) $direction;

        if ($callContext !== null) {
            $this->runtime->context->warn(
                $callContext,
                $this->buildScaleSuggestion($color, 'alpha', $direction, $amount) . ', or '
                . $this->formatColorAdjustHint($color, 'alpha', $this->runtime->formatter->formatSignedNumber($amount)),
                true,
            );
        }

        if ($this->converter->isLegacyColor($color)) {
            return $this->emitModifiedLegacyColor(
                $color,
                fn(array $channels): array => $this->legacyMath->shiftChannel($channels, 'a', $amount),
                true,
            );
        }

        return $this->adjustColor([$color], ['alpha' => new NumberNode($amount)], $context);
    }

    /** @param array<int, AstNode> $positional */
    public function adjustColorChannelByPercent(
        array $positional,
        string $channel,
        int $direction,
        string $context,
        bool $allowCssDefer = false,
        ?BuiltinCallContext $callContext = null,
    ): AstNode {
        if ($allowCssDefer && ! isset($positional[0]) && isset($positional[1])) {
            return new FunctionNode($context, [$positional[1]]);
        }

        if (
            $allowCssDefer
            && ! isset($positional[1])
            && isset($positional[0])
            && $this->runtime->argumentParser->isSpecialNumberNode($positional[0])
        ) {
            throw new DeferToCssFunctionException(
                $this->runtime->argumentParser->callRef($context) . ' should be emitted as a CSS function.',
            );
        }

        $color = $allowCssDefer
            ? $this->runtime->argumentParser->requireColorOrDefer($positional, $context)
            : $this->runtime->argumentParser->requireColor($positional, 0, $context);

        $amount = $this->runtime->argumentParser->asPercentage($positional[1] ?? null, $context) * (float) $direction;

        if ($callContext !== null) {
            $this->runtime->context->warn(
                $callContext,
                $this->buildScaleSuggestion($color, $channel, $direction, $amount) . ', or '
                . $this->formatColorAdjustHint($color, $channel, $this->runtime->formatter->formatSignedPercentage($amount)),
                true,
            );
        }

        if (
            ($channel === 'lightness' || $channel === 'saturation')
            && $this->converter->isLegacyColor($color)
        ) {
            $key = $channel === 'lightness' ? 'l' : 's';

            return $this->emitModifiedLegacyColor(
                $color,
                fn(array $channels): array => $this->legacyMath->shiftChannel($channels, $key, $amount),
            );
        }

        return $this->adjustColor([$color], [$channel => new NumberNode($amount, '%')], $context);
    }

    /**
     * @param array<int, float|null> $channels
     */
    public function serializeModifiedColor(AstNode $original, string $nativeSpace, array $channels, ?float $alpha): AstNode
    {
        foreach ($channels as $i => $channel) {
            if ($channel !== null && abs($channel) < 0.000000000001) {
                $channels[$i] = 0.0;
            }
        }

        [$c0, $c1, $c2] = $channels;

        switch ($nativeSpace) {
            case 'rgb':
                return $this->serializeLegacyRgb($channels, $alpha);

            case 'hsl':
                if ($c0 === null || $c1 === null || $c2 === null || $alpha === null) {
                    return $this->converter->buildModernHslFunctionNode([$c0, $c1, $c2], $alpha);
                }

                if ($original instanceof FunctionNode
                    && $original->originColorSpace === 'hwb'
                    && $this->dartMath->fuzzyEquals($c1, 0.0)
                ) {
                    $c0 = 0.0;
                }

                return $this->buildCommaHslNode($c0, $c1, $c2, $alpha);

            case 'hwb':
                if ($c0 === null || $c1 === null || $c2 === null || $alpha === null) {
                    return $this->converter->buildFunctionalColorNode('hwb', [
                        $c0 === null ? new StringNode('none') : ($c0 == 0.0 ? new StringNode('0deg') : new NumberNode($this->runtime->spaceConverter->normalizeHue($c0), 'deg')),
                        $c1 === null ? new StringNode('none') : new NumberNode($c1, '%'),
                        $c2 === null ? new StringNode('none') : new NumberNode($c2, '%'),
                    ], min($alpha ?? 1.0, 1.0));
                }

                if ($this->isOutOfPercentageRange($c1, $c2) || $c1 + $c2 > 100.0) {
                    $hwbSrgb = $this->dartMath->hwbToSrgb($c0, $c1, $c2);
                    [$h, $saturation, $lightness] = $this->dartMath->srgbToHsl(
                        $hwbSrgb[0],
                        $hwbSrgb[1],
                        $hwbSrgb[2],
                    );

                    return $this->buildCommaHslNode($h, $saturation, $lightness, $alpha);
                }

                $srgb  = $this->dartMath->hwbToSrgb($c0, $c1, $c2);
                $bytes = [$srgb[0] * 255.0, $srgb[1] * 255.0, $srgb[2] * 255.0];

                if ($alpha === 1.0 && ! $this->isOutOfByteRange(...$bytes) && $this->hasFuzzyIntegralBytes($bytes)) {
                    return $this->converter->fromRgb(new RgbColor(
                        r: (int) round($bytes[0]),
                        g: (int) round($bytes[1]),
                        b: (int) round($bytes[2]),
                        a: 1,
                    ));
                }

                [$h, $saturation, $lightness] = $this->dartMath->srgbToHsl($srgb[0], $srgb[1], $srgb[2]);

                return $this->buildCommaHslNode($h, $saturation, $lightness, $alpha);

            case 'lab':
            case 'oklab':
                if ($this->isOutOfLightnessRange($nativeSpace, $c0)) {
                    return $this->buildOutOfRangeFunctionalNode($nativeSpace, $channels, $alpha ?? 1.0);
                }

                return $nativeSpace === 'lab'
                    ? $this->converter->buildLabColorNodeWithNone(['l' => $c0, 'a' => $c1, 'b' => $c2, 'alpha' => $alpha ?? 1.0])
                    : $this->converter->buildOklabColorNodeWithNone([
                        'l'     => $c0 === null ? null : $c0 * 100.0,
                        'a'     => $c1,
                        'b'     => $c2,
                        'alpha' => $alpha ?? 1.0,
                    ]);

            case 'lch':
            case 'oklch':
                if ($this->isOutOfLightnessRange($nativeSpace, $c0)) {
                    return $this->buildOutOfRangeFunctionalNode($nativeSpace, $channels, $alpha ?? 1.0);
                }

                $hue = $c2 === null ? null : $this->runtime->spaceConverter->normalizeHue($c2);

                return $nativeSpace === 'lch'
                    ? $this->converter->buildLchColorNodeWithNone($c0, $c1, $hue, $alpha ?? 1.0)
                    : $this->converter->buildOklchColorNodeWithNone([
                        'l' => $c0 === null ? null : $c0 * 100.0,
                        'c' => $c1,
                        'h' => $hue,
                        'a' => $alpha ?? 1.0,
                    ]);

            default:
                return $this->buildGenericModernNode($nativeSpace, $channels, $alpha);
        }
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function complement(array $positional, array $named = []): AstNode
    {
        $color     = $this->runtime->argumentParser->requireColorOrDefer($positional, 'complement');
        $spaceNode = $named['space'] ?? ($positional[1] ?? null);
        $space     = $spaceNode === null
            ? null
            : strtolower($this->runtime->argumentParser->asString($spaceNode, 'complement'));

        $arguments = ['hue' => new NumberNode(180)];

        if ($space !== null) {
            $arguments['space'] = new StringNode($space);
        } elseif ($this->converter->isLegacyColor($color)) {
            $arguments['space'] = new StringNode('hsl');
        }

        return $this->applyColorOperation(
            [$color],
            $arguments,
            'complement',
            'adjust',
        );
    }

    /** @param array<int, AstNode> $positional */
    public function grayscale(array $positional): AstNode
    {
        $color = $this->runtime->argumentParser->requireColorOrDefer($positional, 'grayscale');

        $named = $this->converter->isLegacyColor($color)
            ? ['saturation' => new NumberNode(0, '%'), 'space' => new StringNode('hsl')]
            : ['chroma' => new NumberNode(0), 'space' => new StringNode('oklch')];

        return $this->applyColorOperation([$color], $named, 'grayscale', 'change');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function mix(array $positional, array $named): AstNode
    {
        $color1 = $positional[0]
            ?? $named['color1']
            ?? throw MissingFunctionArgumentsException::required($this->runtime->context->errorCtx('mix'), 'color');
        $color2 = $positional[1]
            ?? $named['color2']
            ?? throw MissingFunctionArgumentsException::required($this->runtime->context->errorCtx('mix'), 'color');

        $weightNode = $named['weight'] ?? ($positional[2] ?? new NumberNode(50));

        if ($weightNode instanceof NumberNode && $weightNode->unit === '%') {
            $weight = (float) $weightNode->value;
        } elseif ($weightNode instanceof NumberNode) {
            $weight = (float) $weightNode->value;
        } else {
            $weight = $this->runtime->argumentParser->asPercentage($weightNode, 'mix');
        }

        $p = $this->runtime->argumentParser->clamp($weight / 100.0, 1.0);

        if (isset($named['method']) || isset($positional[3])) {
            return $this->interpolateColors($color1, $color2, $named, $positional, $p);
        }

        return $this->mixLegacyColors($color1, $color2, $p);
    }

    private function mixLegacyColors(AstNode $color1, AstNode $color2, float $weightScale): AstNode
    {
        $rgb1 = $this->converter->toRgb($color1);
        $rgb2 = $this->converter->toRgb($color2);

        $normalizedWeight = $weightScale * 2.0 - 1.0;
        $alphaDistance    = $rgb1->a - $rgb2->a;

        $combinedWeight1 = $this->dartMath->fuzzyEquals($normalizedWeight * $alphaDistance, -1.0)
            ? $normalizedWeight
            : ($normalizedWeight + $alphaDistance) / (1.0 + $normalizedWeight * $alphaDistance);

        $weight1 = ($combinedWeight1 + 1.0) / 2.0;
        $weight2 = 1.0 - $weight1;

        return $this->converter->serializeRgbResult(new RgbColor(
            r: $rgb1->rValue() * $weight1 + $rgb2->rValue() * $weight2,
            g: $rgb1->gValue() * $weight1 + $rgb2->gValue() * $weight2,
            b: $rgb1->bValue() * $weight1 + $rgb2->bValue() * $weight2,
            a: $rgb1->a * $weightScale + $rgb2->a * (1.0 - $weightScale),
        ));
    }

    /**
     * @param array<string, AstNode> $named
     * @param array<int, AstNode> $positional
     */
    private function interpolateColors(
        AstNode $color1,
        AstNode $color2,
        array $named,
        array $positional,
        float $weight,
    ): AstNode {
        ['space' => $space, 'hue' => $hueMethod] = $this->resolveMixMethod($named, $positional);

        if (! isset(self::SPACE_CHANNELS[$space])) {
            throw new UnsupportedColorSpaceException($space, 'mix');
        }

        if ($this->dartMath->fuzzyEquals($weight, 0.0)) {
            return $this->nativeChannelsAsColorNode($color2);
        }

        if ($this->dartMath->fuzzyEquals($weight, 1.0)) {
            return $this->nativeChannelsAsColorNode($color1);
        }

        [$ch1, $alphaRaw1] = $this->colorChannelsInSpace($color1, $space);
        [$ch2, $alphaRaw2] = $this->colorChannelsInSpace($color2, $space);

        $hueIndex = in_array($space, ['hsl', 'hwb'], true) ? 0 : (in_array($space, ['lch', 'oklch'], true) ? 2 : null);

        $alpha1 = ($alphaRaw1 ?? $alphaRaw2 ?? 0.0);
        $alpha2 = ($alphaRaw2 ?? $alphaRaw1 ?? 0.0);

        $thisMultiplier  = ($alphaRaw1 ?? 1.0) * $weight;
        $otherMultiplier = ($alphaRaw2 ?? 1.0) * (1.0 - $weight);

        $bothAlphaMissing = $alphaRaw1 === null && $alphaRaw2 === null;
        $mixedAlpha       = $bothAlphaMissing ? null : ($alpha1 * $weight + $alpha2 * (1.0 - $weight));

        $result = [];

        for ($i = 0; $i < 3; $i++) {
            $v1 = $ch1[$i] ?? $ch2[$i];
            $v2 = $ch2[$i] ?? $ch1[$i];

            if ($v1 === null || $v2 === null) {
                $result[$i] = null;

                continue;
            }

            $isHue = $i === $hueIndex;

            $result[$i] = $isHue
                ? $this->interpolateHues($v1, $v2, $hueMethod, $weight)
                : (($v1 * $thisMultiplier + $v2 * $otherMultiplier) / ($mixedAlpha ?? 1.0));
        }

        $resultAlpha = $bothAlphaMissing ? null : $mixedAlpha;

        $originalSpace = $this->normalizeSpaceName($this->converter->detectNativeColorSpace($color1));
        $channels      = [$result[0] ?? null, $result[1] ?? null, $result[2] ?? null];

        if ($space !== $originalSpace) {
            $channels = $this->dartConvert($space, $originalSpace, $channels);

            [$origin1] = $this->colorChannelsInSpace($color1, $originalSpace);
            [$origin2] = $this->colorChannelsInSpace($color2, $originalSpace);

            foreach ([0, 1, 2] as $i) {
                if (($origin1[$i] ?? null) === null && ($origin2[$i] ?? null) === null) {
                    $channels[$i] = null;
                }
            }

            if (($originalSpace === 'lch' || $originalSpace === 'oklch')
                && $channels[1] !== null
                && $this->dartMath->fuzzyEquals($channels[1], 0.0)
            ) {
                $channels[2] = null;
            }

            if (in_array($originalSpace, ['rgb', 'hsl', 'hwb'], true)) {
                $channels = [$channels[0] ?? 0.0, $channels[1] ?? 0.0, $channels[2] ?? 0.0];
            }
        }

        return $this->serializeModifiedColor($color1, $originalSpace, $channels, $resultAlpha);
    }

    /**
     * @return array{0: array<int, float|null>, 1: float|null}
     */
    private function colorChannelsInSpace(AstNode $color, string $space): array
    {
        $nativeSpace = $this->normalizeSpaceName($this->converter->detectNativeColorSpace($color));
        $native      = $this->nativeChannels($color, $nativeSpace);

        /** @var array{0: float|null, 1: float|null, 2: float|null} $nativeChannels */
        $nativeChannels = $native['channels'];

        $channels = [$nativeChannels[0], $nativeChannels[1], $nativeChannels[2]];

        if ($nativeSpace !== $space) {
            $channels = $this->dartConvert($nativeSpace, $space, $channels);

            foreach ([0, 1, 2] as $i) {
                if (($nativeChannels[$i] ?? null) !== null || ($channels[$i] ?? null) === null) {
                    continue;
                }

                $isDestHue = ($space === 'hsl' || $space === 'hwb') && $i === 0;
                $isDestHue = $isDestHue || (($space === 'lch' || $space === 'oklch') && $i === 2);

                if ($isDestHue || abs($channels[$i] ?? 0.0) < 0.000000001) {
                    $channels[$i] = null;
                }
            }
        }

        if (($space === 'lch' || $space === 'oklch')
            && ($channels[1] ?? null) !== null
            && $this->dartMath->fuzzyEquals((float) ($channels[1]), 0.0)
        ) {
            $channels[2] = null;
        } elseif ($space === 'hsl'
            && ($channels[1] ?? null) !== null
            && $this->dartMath->fuzzyEquals((float) ($channels[1]), 0.0)
        ) {
            $channels[0] = null;
        }

        if ($nativeSpace === 'hsl' && ($native['channels'][1] ?? null) === null
            && in_array($space, ['lch', 'oklch', 'oklab', 'lab'], true)
        ) {
            $channels = [($channels[0] ?? 0.0), null, null];
        }

        return [$channels, $native['alpha']];
    }

    private function nativeChannelsAsColorNode(AstNode $color): AstNode
    {
        $space       = $this->normalizeSpaceName($this->converter->detectNativeColorSpace($color));
        $native      = $this->nativeChannels($color, $space);
        $asOriginal  = $this->normalizeSpaceName($space);

        return $this->serializeModifiedColor($color, $asOriginal, $native['channels'], $native['alpha']);
    }

    private function interpolateHues(float $hue1, float $hue2, ?string $method, float $weight): float
    {
        switch ($method) {
            case 'longer':
                $delta = $hue2 - $hue1;

                if ($delta > 0.0 && $delta < 180.0) {
                    $hue2 += 360.0;
                } elseif ($delta > -180.0 && $delta <= 0.0) {
                    $hue1 += 360.0;
                }

                break;

            case 'increasing':
                if ($hue2 < $hue1) {
                    $hue2 += 360.0;
                }

                break;

            case 'decreasing':
                if ($hue1 < $hue2) {
                    $hue1 += 360.0;
                }

                break;

            default:
                $delta = $hue2 - $hue1;

                if ($delta > 180.0) {
                    $hue1 += 360.0;
                } elseif ($delta < -180.0) {
                    $hue2 += 360.0;
                }
        }

        return $hue1 * $weight + $hue2 * (1.0 - $weight);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    private function applyColorOperation(
        array $positional,
        array $named,
        string $context,
        string $mode,
    ): AstNode {
        $requestedSpace = null;

        if (isset($named['space'])) {
            $requestedSpace = $this->normalizeSpaceName(
                $this->runtime->argumentParser->asString($named['space'], $context),
            );
            unset($named['space']);
        }

        $color       = $this->runtime->argumentParser->requireColorOrDefer($positional, $context);
        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        $hwbOriginChannels = null;

        if (
            $nativeSpace === 'hsl'
            && $color instanceof FunctionNode
            && $color->originColorSpace === 'hwb'
            && $color->originSrgbChannels !== null
            && $this->sniffLegacySpace($named) === 'hwb'
        ) {
            $nativeSpace       = 'hwb';
            $hwbOriginChannels = $this->dartMath->convertNumeric('srgb', 'hwb', $color->originSrgbChannels);
        }

        $targetSpace = $requestedSpace
            ?? ($this->converter->isLegacyColor($color)
                ? ($this->sniffLegacySpace($named) ?? $this->normalizeSpaceName($nativeSpace))
                : $this->normalizeSpaceName($nativeSpace));

        if (! isset(self::SPACE_CHANNELS[$targetSpace])) {
            throw new UnsupportedColorSpaceException($targetSpace, $context);
        }

        $isChange = $mode === 'change';
        $provided = $this->parseProvidedChannels($named, $targetSpace, $isChange, $mode);

        if ($provided === [] && $targetSpace === $nativeSpace) {
            return $color;
        }

        $native = $this->nativeChannels($color, $nativeSpace);

        if ($hwbOriginChannels !== null) {
            $native['channels'] = $hwbOriginChannels;
        }

        $channels = $native['channels'];

        if ($targetSpace !== $nativeSpace) {
            $channels = $this->preserveAnalogousMissingChannels(
                $this->dartConvert($nativeSpace, $targetSpace, $channels),
                $channels,
                $nativeSpace,
                $targetSpace,
            );
        }

        [$channels, $alpha] = $this->modifyChannels($channels, $native['alpha'], $provided, $targetSpace, $mode);

        if ($targetSpace !== $nativeSpace) {
            $targetChannels = $channels;
            $channels       = $this->preserveAnalogousMissingChannels(
                $this->dartConvert($targetSpace, $nativeSpace, $channels),
                $targetChannels,
                $targetSpace,
                $nativeSpace,
            );

            if (($nativeSpace === 'lch' || $nativeSpace === 'oklch')
                && $channels[1] !== null
                && $this->dartMath->fuzzyEquals($channels[1], 0.0)
            ) {
                $channels[2] = null;
            }

            if (in_array($nativeSpace, ['rgb', 'hsl', 'hwb'], true)) {
                $channels = [$channels[0] ?? 0.0, $channels[1] ?? 0.0, $channels[2] ?? 0.0];
            }
        }

        return $this->serializeModifiedColor($color, $nativeSpace, $channels, $alpha);
    }

    /**
     * @param array<int, float|null> $destChannels
     * @param array<int, float|null> $sourceChannels
     * @return array<int, float|null>
     */
    private function preserveAnalogousMissingChannels(
        array $destChannels,
        array $sourceChannels,
        string $sourceSpace,
        string $destSpace,
    ): array {
        $sourceCategories = self::SPACE_CHANNEL_CATEGORIES[$sourceSpace] ?? null;
        $destCategories   = self::SPACE_CHANNEL_CATEGORIES[$destSpace] ?? null;

        if ($sourceCategories === null || $destCategories === null) {
            return $destChannels;
        }

        foreach ([0, 1, 2] as $sourceIndex) {
            if (($sourceChannels[$sourceIndex] ?? null) !== null) {
                continue;
            }

            $category = $sourceCategories[$sourceIndex] ?? null;

            if ($category === null) {
                continue;
            }

            $destIndex = array_search($category, $destCategories, true);

            if ($destIndex !== false) {
                $destChannels[$destIndex] = null;
            }
        }

        return $destChannels;
    }

    private function normalizeSpaceName(string $space): string
    {
        return $space === 'xyz-d65' ? 'xyz' : $space;
    }

    /**
     * @param array<string, AstNode> $named
     */
    private function sniffLegacySpace(array $named): ?string
    {
        foreach ($named as $name => $value) {
            $key = strtolower($name);

            if ($key === 'red' || $key === 'green' || $key === 'blue') {
                return 'rgb';
            }

            if ($key === 'saturation' || $key === 'lightness') {
                return 'hsl';
            }

            if ($key === 'whiteness' || $key === 'blackness') {
                return 'hwb';
            }
        }

        foreach ($named as $name => $value) {
            if (strtolower($name) === 'hue') {
                return 'hsl';
            }
        }

        return null;
    }

    private function dartSpaceName(string $space): string
    {
        return $space === 'xyz' ? 'xyz-d65' : $space;
    }

    /**
     * @param array<int, float|null> $channels
     * @return array<int, float|null>
     */
    private function dartConvert(string $from, string $to, array $channels): array
    {
        return $this->dartMath->convert($this->dartSpaceName($from), $this->dartSpaceName($to), $channels);
    }

    /**
     * @param array<string, AstNode> $named
     * @return array<string, float|null> provided values; null means an explicit `none`
     */
    private function parseProvidedChannels(array $named, string $targetSpace, bool $isChange, string $mode = 'change'): array
    {
        if ($named === []) {
            return [];
        }

        $schema = self::SPACE_CHANNELS[$targetSpace];
        $types  = self::SPACE_CHANNEL_TYPES[$targetSpace];
        $values = [];

        foreach ($named as $name => $node) {
            $key = strtolower($name);

            if ($key === 'alpha') {
                if (! $isChange && AstValueInspector::isNoneKeyword($node)) {
                    throw new MissingFunctionArgumentsException(
                        $this->runtime->context->errorCtx('color'),
                        'number arguments',
                    );
                }

                $values['alpha'] = $this->channelValueFromNode(
                    $node,
                    match ($mode) {
                        'adjust' => 'alpha-adjust',
                        'scale'  => 'percent',
                        default  => 'alpha',
                    },
                );

                continue;
            }

            $index = array_search($key, $schema, true);

            if ($index === false) {
                throw new UnknownColorChannelException($targetSpace, $key);
            }

            if (! $isChange && AstValueInspector::isNoneKeyword($node)) {
                throw new MissingFunctionArgumentsException(
                    $this->runtime->context->errorCtx('color'),
                    'number arguments',
                );
            }

            $values[$key] = $this->channelValueFromNode($node, $mode === 'scale' ? 'percent' : $types[$index]);
        }

        return $values;
    }

    private function channelValueFromNode(AstNode $node, string $type): ?float
    {
        if (AstValueInspector::isNoneKeyword($node)) {
            return null;
        }

        if (! $node instanceof NumberNode) {
            throw new MissingFunctionArgumentsException(
                $this->runtime->context->errorCtx('color'),
                'number arguments',
            );
        }

        $value = (float) $node->value;

        if ($type === 'hue') {
            return $this->runtime->argumentParser->asHueAngle($node, 'color');
        }

        $unit = $node->unit;

        return match ($type) {
            'byte'           => $unit === '%' ? $value * 2.55 : $value,
            'unit01'         => $unit === '%' ? $value / 100.0 : $value,
            'percent',
            'percent-flex',
            'percent-sat'    => $value,
            'fraction01'     => $unit === '%' ? $value / 100.0 : $value,
            'lab-ab'         => $unit === '%' ? $value * 1.25 : $value,
            'oklab-ab'       => $unit === '%' ? $value * 0.004 : $value,
            'lch-c'          => $unit === '%' ? $value * 1.5 : $value,
            'oklch-c'        => $unit === '%' ? $value * 0.004 : $value,
            'alpha'          => $unit === '%' ? $value / 100.0 : $value,
            'alpha-adjust'   => $value,
            default          => throw new UnsupportedColorSpaceException($type, 'color'),
        };
    }

    /**
     * @return array{channels: list<float|null>, alpha: float|null}
     */
    /**
     * @return array{channels: array{0: float|null, 1: float|null, 2: float|null}, alpha: float|null}
     */
    private function nativeChannels(AstNode $color, string $nativeSpace): array
    {
        if (! $color instanceof FunctionNode) {
            $rgb = $this->converter->toRgb($color);

            return [
                'channels' => [$rgb->rValue(), $rgb->gValue(), $rgb->bValue()],
                'alpha'    => $rgb->a,
            ];
        }

        [$nodes, $alphaNode] = $this->converter->extractRawChannelsPublic($color);

        $isColorFunction = strtolower($color->name) === 'color';

        if ($alphaNode === null && ! $isColorFunction && count($nodes) > 3) {
            $alphaNode = $nodes[3];
            $nodes     = array_slice($nodes, 0, 3);
        }

        $offset = 0;
        $types  = self::SPACE_CHANNEL_TYPES[$nativeSpace] ?? null;

        if ($types === null || $isColorFunction) {
            $offset = $isColorFunction ? 1 : 0;
            $types  = self::SPACE_CHANNEL_TYPES[$this->converter->detectGenericColorSpace($color)];
        }

        $channels = [];

        for ($i = 0; $i < 3; $i++) {
            $node = $nodes[$offset + $i] ?? null;

            $channels[] = $node === null || AstValueInspector::isNoneKeyword($node)
                ? null
                : $this->channelValueFromNode($node, $types[$i]);
        }

        if (($nativeSpace === 'lch' || $nativeSpace === 'oklch')
            && $channels[1] !== null
            && $this->dartMath->fuzzyEquals($channels[1], 0.0)
        ) {
            $channels[2] = null;
        }

        if ($alphaNode !== null) {
            $alpha = AstValueInspector::isNoneKeyword($alphaNode)
                ? null
                : $this->channelValueFromNode($alphaNode, 'alpha');
        } else {
            $alpha = 1.0;
        }

        /** @var array{channels: array{float|null, float|null, float|null}, alpha: float|null} $result */
        $result = ['channels' => $channels, 'alpha' => $alpha];

        return $result;
    }

    /**
     * @param array<int, float|null> $channels
     * @param array<string, float|null> $provided
     * @return array{0: array<int, float|null>, 1: float|null}
     */
    private function modifyChannels(
        array $channels,
        ?float $alpha,
        array $provided,
        string $targetSpace,
        string $mode,
    ): array {
        $schema   = self::SPACE_CHANNELS[$targetSpace];
        $types    = self::SPACE_CHANNEL_TYPES[$targetSpace];
        $isChange = $mode === 'change';

        foreach ($schema as $i => $name) {
            if (! array_key_exists($name, $provided)) {
                continue;
            }

            $value = $provided[$name];

            if ($value === null) {
                $channels[$i] = null;

                continue;
            }

            $oldValue = $channels[$i];

            if ($isChange) {
                $result = $name === 'hue'
                    ? $this->runtime->spaceConverter->normalizeHue($value)
                    : $value;
            } elseif ($mode === 'adjust') {
                $result = ($oldValue ?? 0.0) + $value;
                $bounds = self::CHANNEL_TYPE_BOUNDS[$types[$i]] ?? null;

                if ($bounds !== null) {
                    [$min, $max] = $bounds;

                    if ($result < $min) {
                        $result = $oldValue !== null && $oldValue < $min
                            ? max($oldValue, $result)
                            : $min;
                    }

                    if ($result > $max) {
                        $result = $oldValue !== null && $oldValue > $max
                            ? min($oldValue, $result)
                            : $max;
                    }
                }
            } else {
                $result = $this->scaleChannelValue($types[$i], $oldValue, $value);
            }

            $channels[$i] = $result;
        }

        if ($isChange
            && ($targetSpace === 'lch' || $targetSpace === 'oklch')
            && $channels[1] !== null
            && $channels[1] < 0.0
        ) {
            $channels[1] = abs($channels[1]);

            if ($channels[2] !== null) {
                $channels[2] = $this->runtime->spaceConverter->normalizeHue($channels[2] + 180.0);
            }
        }

        if (array_key_exists('alpha', $provided)) {
            $value = $provided['alpha'];

            $alpha = match (true) {
                $value === null    => null,
                $isChange          => $this->runtime->argumentParser->clamp($value, 1.0),
                $mode === 'adjust' => $this->runtime->argumentParser->clamp(($alpha ?? 1.0) + $value, 1.0),
                default            => $this->runtime->argumentParser->clamp(
                    $this->scaleChannelValue('alpha', ($alpha ?? 1.0), $value),
                    1.0,
                ),
            };
        }

        return [$channels, $alpha];
    }

    private function scaleChannelValue(string $type, ?float $oldValue, float $percentage): float
    {
        if ($oldValue === null) {
            return 0.0;
        }

        $range = self::CHANNEL_TYPE_RANGES[$type] ?? null;

        if ($range === null) {
            return $oldValue;
        }

        [$min, $max] = $range;

        $factor = $percentage / 100.0;

        return match (true) {
            $factor == 0.0 => $oldValue,
            $factor > 0.0  => $oldValue >= $max ? $oldValue : $oldValue + ($max - $oldValue) * $factor,
            default        => $oldValue <= $min ? $oldValue : $oldValue + ($oldValue - $min) * $factor,
        };
    }

    /**
     * @param array<int, float|null> $channels rgb bytes
     */
    private function serializeLegacyRgb(array $channels, ?float $alpha, bool $hslDerived = false): AstNode
    {
        [$c0, $c1, $c2] = $channels;

        $resolvedAlpha = $alpha ?? 1.0;

        if ($c0 === null || $c1 === null || $c2 === null || $alpha === null) {
            return $this->converter->buildModernRgbFunctionNode([$c0, $c1, $c2], $alpha);
        }

        if ($this->isOutOfByteRange($c0, $c1, $c2)) {
            $collapsed = $this->converter->serializeAsUnclampedHsl($c0, $c1, $c2, $resolvedAlpha);

            $collapsed->originColorSpace = 'rgb';

            return $collapsed;
        }

        $opaque = abs($resolvedAlpha - 1.0) < 0.000001;

        if ($opaque && $this->hasFuzzyIntegralBytes($channels)) {
            return $this->converter->fromRgb(new RgbColor(r: $c0, g: $c1, b: $c2, a: 1));
        }

        $canPrintBytes = $hslDerived
            ? $this->hasBoundaryBytes($channels)
            : ($c0 === round($c0) && $c1 === round($c1) && $c2 === round($c2));

        if ($canPrintBytes) {
            $nodes = [new NumberNode(round($c0)), new NumberNode(round($c1)), new NumberNode(round($c2))];
            $name  = $opaque ? 'rgb' : 'rgba';

            return new FunctionNode(
                $name,
                $opaque ? $nodes : [...$nodes, $this->converter->buildAlphaNode($resolvedAlpha)],
            );
        }

        $percentNodes = [
            new NumberNode($c0 * 100.0 / 255.0, '%'),
            new NumberNode($c1 * 100.0 / 255.0, '%'),
            new NumberNode($c2 * 100.0 / 255.0, '%'),
        ];

        if (! $opaque) {
            return new FunctionNode('rgba', [...$percentNodes, $this->converter->buildAlphaNode($resolvedAlpha)]);
        }

        return new FunctionNode('rgb', $percentNodes);
    }

    private function isOutOfByteRange(float ...$channels): bool
    {
        foreach ($channels as $channel) {
            if ($channel < 0.0 || $channel > 255.0) {
                return true;
            }
        }

        return false;
    }

    private function isOutOfPercentageRange(float ...$channels): bool
    {
        foreach ($channels as $channel) {
            if ($channel < 0.0 || $channel > 100.0) {
                return true;
            }
        }

        return false;
    }

    private function isOutOfLightnessRange(string $space, ?float $lightness): bool
    {
        if ($lightness === null) {
            return false;
        }

        $limit = $space === 'oklab' || $space === 'oklch' ? 1.0 : 100.0;

        return $lightness < -$limit * 0.000000001 || $lightness > $limit + $limit * 0.000000001;
    }

    /**
     * @param array<int, float|null> $channels
     */
    private function hasFuzzyIntegralBytes(array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($channel === null || ! $this->dartMath->fuzzyIsInt($channel)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, float|null> $channels
     */
    private function hasBoundaryBytes(array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($channel === null || ! ($channel === 0.0 || $channel === 255.0)) {
                return false;
            }
        }

        return true;
    }

    private function buildCommaHslNode(float $hue, float $saturation, float $lightness, float $alpha): FunctionNode
    {
        $arguments = [
            new NumberNode($this->runtime->spaceConverter->normalizeHue($hue), null, false),
            new NumberNode($saturation, '%', false),
            new NumberNode($lightness, '%', false),
        ];

        if (abs($alpha - 1.0) >= 0.000001) {
            return new FunctionNode('hsla', [...$arguments, $this->converter->buildAlphaNode($alpha)]);
        }

        return new FunctionNode('hsl', $arguments);
    }

    /**
     * @param array<int, float|null> $channels
     */
    private function buildGenericModernNode(string $space, array $channels, ?float $alpha): FunctionNode
    {
        $trim  = fn(float $value): float => (float) $this->runtime->spaceConverter->trimFloat($value, 10);
        $items = [new StringNode($space)];

        foreach ($channels as $channel) {
            $items[] = $channel === null ? new StringNode('none') : new NumberNode($trim($channel));
        }

        if ($alpha === null) {
            $items[] = new StringNode('/');
            $items[] = new StringNode('none');
        } elseif ($alpha < 1.0) {
            $items[] = new StringNode('/');
            $items[] = $this->converter->buildAlphaNode($alpha);
        }

        return new FunctionNode('color', [new ListNode($items, 'space')]);
    }

    /**
     * @param array<int, float|null> $channels
     */
    private function buildOutOfRangeFunctionalNode(string $space, array $channels, float $alpha): FunctionNode
    {
        $xyz = $this->dartMath->convertNumeric($this->dartSpaceName($space), 'xyz-d65', [
            $channels[0] ?? 0.0,
            $channels[1] ?? 0.0,
            $channels[2] ?? 0.0,
        ]);

        $xyzNode = $this->converter->buildGenericColorFunctionNode('xyz', $xyz, $alpha);

        return new FunctionNode('color-mix', [
            new ListNode([new StringNode('in'), new StringNode($space)], 'space'),
            new ListNode([$xyzNode, new NumberNode(100, '%')], 'space'),
            new StringNode('black'),
        ]);
    }

    /**
     * @param callable(array{h: float, s: float, l: float, a: float}): array{h: float, s: float, l: float, a: float} $modify
     */
    private function emitModifiedLegacyColor(AstNode $color, callable $modify, bool $alphaOnly = false): AstNode
    {
        $rgb       = $this->converter->toRgb($color);
        $legacyHsl = $this->extractLegacyHsl($color) ?? [
            'channels' => $this->legacyMath->rgbToHsl($rgb),
            'origin'   => 'rgb',
        ];

        $modified = $modify($legacyHsl['channels']);

        if ($legacyHsl['origin'] === 'rgb') {
            if (! $alphaOnly) {
                $rgb = $this->legacyMath->hslToRgb($modified['h'], $modified['s'], $modified['l'], $modified['a']);
            }

            return $this->serializeLegacyRgb(
                [$rgb->rValue(), $rgb->gValue(), $rgb->bValue()],
                $alphaOnly ? $modified['a'] : $rgb->a,
                ! $alphaOnly,
            );
        }

        return $this->emitLegacyHsl($modified, $legacyHsl['origin']);
    }

    /**
     * @return array{channels: array{h: float, s: float, l: float, a: float}, origin: string}|null
     */
    private function extractLegacyHsl(AstNode $color): ?array
    {
        if ($color instanceof FunctionNode) {
            $name = strtolower($color->name);

            if ($name === 'hsl' || $name === 'hsla') {
                [$channels, $alpha] = $this->converter->extractRawChannelsPublic($color);

                if (
                    ! isset($channels[0], $channels[1], $channels[2])
                    || $this->runtime->argumentParser->isMissingChannelNode($channels[0])
                    || $this->runtime->argumentParser->isMissingChannelNode($channels[1])
                    || $this->runtime->argumentParser->isMissingChannelNode($channels[2])
                ) {
                    return null;
                }

                return [
                    'channels' => [
                        'h' => $this->runtime->argumentParser->asNumber($channels[0], 'hsl'),
                        's' => $this->runtime->argumentParser->asPercentage($channels[1], 'hsl'),
                        'l' => $this->runtime->argumentParser->asPercentage($channels[2], 'hsl'),
                        'a' => $this->converter->parseAlphaPublic($alpha, 'hsl'),
                    ],
                    'origin' => 'hsl',
                ];
            }

            if ($name === 'hwb') {
                [$channels, $alpha] = $this->converter->extractRawChannelsPublic($color);

                if (! isset($channels[0]) || $this->runtime->argumentParser->isMissingChannelNode($channels[0])) {
                    return null;
                }

                $whiteness = isset($channels[1]) && ! $this->runtime->argumentParser->isMissingChannelNode($channels[1])
                    ? $this->runtime->argumentParser->asPercentage($channels[1], 'hwb')
                    : 0.0;
                $blackness = isset($channels[2]) && ! $this->runtime->argumentParser->isMissingChannelNode($channels[2])
                    ? $this->runtime->argumentParser->asPercentage($channels[2], 'hwb')
                    : 0.0;

                [$r, $g, $b] = $this->runtime->spaceConverter->hwbToRgb(
                    $this->runtime->argumentParser->asNumber($channels[0], 'hwb'),
                    $whiteness / 100.0,
                    $blackness / 100.0,
                );

                return [
                    'channels' => $this->legacyMath->rgbToHsl(new RgbColor(
                        r: $r * 255.0,
                        g: $g * 255.0,
                        b: $b * 255.0,
                        a: $this->converter->parseAlphaPublic($alpha, 'hwb'),
                    )),
                    'origin' => 'hwb',
                ];
            }
        }

        if (! $this->converter->isLegacyColor($color)) {
            return null;
        }

        return [
            'channels' => $this->legacyMath->rgbToHsl($this->converter->toRgb($color)),
            'origin'   => 'rgb',
        ];
    }

    /**
     * @param array{h: float, s: float, l: float, a: float} $channels
     */
    private function emitLegacyHsl(array $channels, string $origin): AstNode
    {
        if ($origin === 'hwb' && abs($channels['s']) < 0.0000001) {
            $channels['h'] = 0.0;
        }

        return $this->converter->buildHslFunctionNode(
            $channels['h'],
            $channels['s'],
            $channels['l'],
            $channels['a'],
        );
    }

    private function formatColorAdjustHint(AstNode $color, string $channel, string $formattedAmount): string
    {
        return $this->formatColorFunctionHint('color.adjust', $color, $channel, $formattedAmount);
    }

    private function formatColorFunctionHint(
        string $function,
        AstNode $color,
        string $channel,
        string $formattedAmount,
    ): string {
        return sprintf(
            '%s(%s, $%s: %s)',
            $function,
            $this->runtime->formatter->describeValue($color),
            $channel,
            $formattedAmount,
        );
    }

    private function buildScaleSuggestion(AstNode $color, string $channel, int $direction, float $amount): string
    {
        $max = $channel === 'alpha' ? 1.0 : 100.0;

        $current = match ($channel) {
            'lightness'  => $this->converter->toHsl($color)->lValue(),
            'saturation' => $this->converter->toHsl($color)->sValue(),
            default      => $channel === 'alpha' ? $this->converter->toAlpha($color) : 0.0,
        };

        $remaining = $direction > 0 ? ($max - $current) : $current;

        if (abs($remaining) < 0.000001) {
            $scale = 0.0;
        } else {
            $scale = min(100.0, abs($amount) / $remaining * 100.0);
        }

        return $this->formatColorFunctionHint(
            'color.scale',
            $color,
            $channel,
            $this->runtime->formatter->formatSignedPercentage($direction > 0 ? $scale : -$scale),
        );
    }

    /**
     * @param array<string, AstNode> $named
     * @param array<int, AstNode> $positional
     * @return array{space: string, hue: ?string}
     */
    private function resolveMixMethod(array $named, array $positional): array
    {
        $methodNode = $named['method'] ?? ($positional[3] ?? null);
        $methodText = null;

        if ($methodNode instanceof StringNode) {
            $methodText = $methodNode->value;
        } elseif ($methodNode instanceof ListNode) {
            $parts = [];

            foreach ($methodNode->items as $item) {
                if ($item instanceof StringNode) {
                    $parts[] = $item->value;
                }
            }

            $methodText = implode(' ', $parts);
        }

        if ($methodText === null) {
            return ['space' => 'rgb', 'hue' => null];
        }

        $parts = array_values(
            array_filter(
                explode(' ', strtolower(trim($methodText))),
                static fn(string $part): bool => $part !== '',
            ),
        );

        if ($parts === []) {
            return ['space' => 'rgb', 'hue' => null];
        }

        $space = $parts[0];
        $hue   = null;

        if (count($parts) === 3 && $parts[2] === 'hue') {
            $hue = $parts[1];
        }

        return ['space' => $space, 'hue' => $hue];
    }
}
