<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\OklabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Operations\ColorFunctionEvaluator;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Builtins\Color\Support\LchChannelData;
use Bugo\SCSS\Builtins\Color\Support\RgbChannelScale;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\AstValueInspector;

use function abs;
use function array_merge;
use function cos;
use function count;
use function deg2rad;
use function in_array;
use function max;
use function min;
use function round;
use function sin;
use function sqrt;
use function strtolower;

use const M_PI;

/**
 * @phpstan-type ChannelVector array<int, float|null>
 *
 * @psalm-type  ChannelVector = array<int, float|null>
 */
final class ColorSpaceConverter
{
    public ?ColorFunctionEvaluator $functionEvaluator;

    private const UNBOUNDED_GAMUT_SPACES = ['xyz', 'xyz-d50', 'lab', 'lch', 'oklab', 'oklch'];

    private const LIGHTNESS_BOUNDARY_EPSILON = 1e-10;

    private const LOCAL_MINDE_JND = 0.02;

    private const LOCAL_MINDE_EPSILON = 0.0001;

    public function __construct(
        private readonly ColorRuntime $runtime,
        private readonly ColorNodeConverter $converter,
        private readonly DartColorMath $dartMath = new DartColorMath(),
    ) {
        $this->functionEvaluator = null;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function toSpace(array $positional): AstNode
    {
        $color       = $this->runtime->argumentParser->requireColor($positional, 0, 'to-space');
        $space       = strtolower($this->runtime->argumentParser->asString($positional[1] ?? null, 'to-space'));
        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (
            $color instanceof FunctionNode
            && ($nativeSpace === $space || ($nativeSpace === 'xyz' && $space === 'xyz-d65'))
            && ! in_array($space, ['rgb', 'hsl', 'hwb'], true)
        ) {
            if (in_array($space, ['lch', 'lab', 'oklch', 'oklab'], true) && $this->isLightnessOutOfRange($color, $nativeSpace)) {
                $xyz   = $this->extractXyzD65ForColorMix($color, $space);
                $alpha = $this->converter->toAlpha($color);

                return $this->buildColorMixNode($space, $xyz, $alpha);
            }

            return $color;
        }

        if (
            ($color instanceof FunctionNode || $color instanceof ColorNode)
            && $nativeSpace === $space
            && in_array($space, ['rgb', 'hsl'], true)
        ) {
            [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color);

            $inGamut = $r >= 0.0 && $r <= 255.0
                && $g >= 0.0 && $g <= 255.0
                && $b >= 0.0 && $b <= 255.0;

            if ($inGamut || ($space === 'hsl' && $color instanceof FunctionNode)) {
                return $color;
            }
        }

        if (
            $color instanceof FunctionNode
            && $space === 'rgb'
            && in_array(strtolower($color->name), ['hsl', 'hsla'], true)
        ) {
            [$srgbR, $srgbG, $srgbB] = $this->extractUnclampedSrgbChannels($color);

            $outOfGamut = $srgbR < 0.0 || $srgbR > 255.0
                || $srgbG < 0.0 || $srgbG > 255.0
                || $srgbB < 0.0 || $srgbB > 255.0;

            if ($outOfGamut) {
                return $color;
            }
        }

        if ($this->hasAllChannelsMissing($color) && ! in_array($space, ['rgb', 'hsl', 'hwb'], true)) {
            $channels = [
                $this->missingStringNode(),
                $this->missingStringNode(),
                $this->missingStringNode(),
            ];

            if (in_array($space, ['lch', 'lab', 'oklch', 'oklab'], true)) {
                return $this->converter->buildFunctionalColorNode($space, $channels, $this->converter->toAlpha($color));
            }

            return $this->converter->buildFunctionalColorNode('color', [
                new StringNode($space),
                ...$channels,
            ], $this->converter->toAlpha($color));
        }

        if ($space === 'lch') {
            return $this->convertToLch($color);
        }

        if ($space === 'oklch') {
            return $this->convertToOklch($color);
        }

        if ($space === 'lab') {
            return $this->convertToLab($color);
        }

        if ($space === 'oklab') {
            return $this->convertToOklab($color);
        }

        if ($space === 'xyz-d50') {
            return $this->convertToXyzD50Space($color);
        }

        if ($space === 'xyz' || $space === 'xyz-d65') {
            return $this->convertToXyzSpace($color, $space);
        }

        if (in_array($space, ['display-p3-linear', 'display-p3', 'srgb-linear', 'srgb', 'a98-rgb', 'rec2020', 'prophoto-rgb'], true)) {
            return $this->convertToRgbFamilyTarget($color, $space);
        }

        if (! in_array($space, ['rgb', 'hsl', 'hwb'], true)) {
            throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('to-space'));
        }

        if ($space === 'hsl' || $space === 'hwb') {
            return $this->convertToHslOrHwb($color, $space);
        }

        return $this->convertToRgbOutput($color);
    }

    public function toOklchPreservingMissingChannels(AstNode $color): OklchColor
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $channels  = $this->converter->extractChannelNodes($color);
            $lightness = $this->runtime->argumentParser->isMissingChannelNode($channels[0] ?? new StringNode('none'))
                ? 0.0
                : $this->runtime->argumentParser->clamp(
                    $this->runtime->argumentParser->asPercentage($channels[0] ?? null, 'to-space'),
                    100.0,
                );

            $chroma = $this->runtime->argumentParser->asAbsoluteChannel(
                $channels[1] ?? null,
                'to-space',
                150.0,
            );

            $hue = $this->runtime->argumentParser->normalizeHue(
                $this->runtime->argumentParser->asHueAngle($channels[2] ?? null, 'to-space'),
            );

            return $this->runtime->spaceConverter->xyzD65ToOklch(
                $this->runtime->spaceConverter->lchToXyzD65($lightness, $chroma, $hue),
            );
        }

        return $this->runtime->spaceConverter->rgbToOklch(
            RgbChannelScale::toNormalized($this->converter->toRgb($color)),
        );
    }

    public function extractOklchMixData(AstNode $color): LchChannelData
    {
        return $this->converter->extractOklchMixData($color, 'mix');
    }

    public function extractLchMissingData(AstNode $color): LchChannelData
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

            [$channels, $alpha] = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

            $lightnessMissing = $this->runtime->argumentParser->isMissingChannelNode($channels[0] ?? new StringNode('none'));
            $chromaMissing    = $this->runtime->argumentParser->isMissingChannelNode($channels[1] ?? new StringNode('none'));
            $hueMissing       = $this->runtime->argumentParser->isMissingChannelNode($channels[2] ?? new StringNode('none'));

            return new LchChannelData(
                $lightnessMissing ? 0.0 : $this->runtime->argumentParser->asPercentage($channels[0] ?? null, 'to-space'),
                $chromaMissing ? 0.0 : $this->runtime->argumentParser->asAbsoluteChannel($channels[1] ?? null, 'to-space', 150.0),
                $hueMissing ? 0.0 : $this->runtime->argumentParser->normalizeHue($this->runtime->argumentParser->asHueAngle($channels[2] ?? null, 'to-space')),
                $this->converter->parseAlphaPublic($alpha, 'to-space'),
                $lightnessMissing,
                $chromaMissing,
                $hueMissing,
            );
        }

        $rgb = $this->converter->toRgb($color);
        $lch = $this->runtime->spaceConverter->rgbToLch(RgbChannelScale::toNormalized($rgb));

        return new LchChannelData(
            $lch->lValue(),
            $lch->cValue(),
            $lch->hValue(),
            $lch->alpha,
        );
    }

    public function toHslWithMissingChannels(AstNode $color): ?HslColor
    {
        if (! ($color instanceof FunctionNode)) {
            $hsl = $this->converter->toHsl($color);

            return new HslColor(
                h: $hsl->h,
                s: $hsl->s,
                l: $hsl->l,
                a: $hsl->a,
            );
        }

        if (strtolower($color->name) !== 'hsl' && strtolower($color->name) !== 'hsla') {
            return null;
        }

        /** @var array<int, AstNode> $expandedArgs */
        $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

        [$channels, $alpha] = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

        if (count($channels) !== 3) {
            return null;
        }

        $hNone = $this->runtime->argumentParser->isMissingChannelNode($channels[0]);
        $sNone = $this->runtime->argumentParser->isMissingChannelNode($channels[1]);
        $lNone = $this->runtime->argumentParser->isMissingChannelNode($channels[2]);

        $hue = $hNone ? null : $this->runtime->argumentParser->normalizeHue(
            $this->runtime->argumentParser->asNumber($channels[0], 'mix'),
        );

        $sat = $sNone ? null : $this->runtime->argumentParser->clamp(
            $this->runtime->argumentParser->asPercentage($channels[1], 'mix'),
            100.0,
        );

        $lig = $lNone ? null : $this->runtime->argumentParser->clamp(
            $this->runtime->argumentParser->asPercentage($channels[2], 'mix'),
            100.0,
        );

        $alp = 1.0;

        if ($alpha !== null) {
            $alp = $this->runtime->argumentParser->isMissingChannelNode($alpha)
                ? 0.0
                : $this->runtime->argumentParser->clamp(
                    $this->runtime->argumentParser->asNumber($alpha, 'mix'),
                    1.0,
                );
        }

        return new HslColor($hue, $sat, $lig, $alp);
    }

    public function isSemanticChannelMissing(AstNode $color): bool
    {
        if (! ($color instanceof FunctionNode)) {
            return false;
        }

        $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
        $channelIndex = $this->runtime->channelSchema->lightnessIndexForFunction(strtolower($color->name));

        if ($channelIndex === null || ! isset($expandedArgs[$channelIndex])) {
            return false;
        }

        return $this->runtime->argumentParser->isMissingChannelNode($expandedArgs[$channelIndex]);
    }

    public function missingStringNode(): StringNode
    {
        return new StringNode('none');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function toGamut(array $positional, array $named): AstNode
    {
        $rawNode = $positional[0] ?? null;
        $color   = $this->runtime->argumentParser->requireColor($positional, 0, 'to-gamut');
        $method  = strtolower($this->runtime->argumentParser->asString(
            $named['method'] ?? ($positional[2] ?? new StringNode('local-minde')),
            'to-gamut',
        ));

        if (! in_array($method, ['local-minde', 'clip'], true)) {
            throw new UnsupportedColorValueException("Unknown gamut mapping method \"$method\".");
        }

        $spaceNode     = $named['space'] ?? ($positional[1] ?? null);
        $originalSpace = $this->normalizeGamutSpace($this->converter->detectNativeColorSpace($color));
        $explicitSpace = $spaceNode !== null
            ? $this->normalizeGamutSpace(strtolower($this->runtime->argumentParser->asString($spaceNode, 'to-gamut')))
            : null;

        [$srcSpace, $srcChannels] = $this->extractToGamutSourceChannels($color);

        $alpha = $this->converter->toAlpha($color);

        if ($originalSpace === 'hsl' && ($srcSpace === 'rgb' || $srcSpace === 'hwb')) {
            $originalSpace = $srcSpace;
        }

        $destSpace = $explicitSpace ?? $originalSpace;

        if (in_array($destSpace, self::UNBOUNDED_GAMUT_SPACES, true)) {
            if ($rawNode instanceof FunctionNode && $this->isOutOfGamutColorMixNode($rawNode)) {
                return $rawNode;
            }

            if ($color instanceof FunctionNode && $this->isLightnessOutOfRange($color, $destSpace)) {
                return $this->buildColorMixNode(
                    $destSpace,
                    $this->extractXyzD65ForColorMix($color, $destSpace),
                    $this->converter->toAlpha($color),
                );
            }

            return $color;
        }

        $working = $srcSpace === $destSpace
            ? $srcChannels
            : $this->dartMath->convert(
                $srcSpace === 'xyz' ? 'xyz-d65' : $srcSpace,
                $destSpace,
                $srcChannels,
            );

        $beforeMapping = $working;

        $working = $method === 'clip'
            ? $this->clipToGamutRange($destSpace, $working)
            : $this->applyLocalMindeGamutMapping($destSpace, $working);

        $srcIsPolar   = $srcSpace === 'lch' || $srcSpace === 'oklch';
        $powerlessHue = $srcIsPolar
            && $srcChannels[1] !== null
            && $this->dartMath->fuzzyEquals($srcChannels[1], 0.0);

        $legacyMissingCollapse = in_array($originalSpace, ['rgb', 'hsl', 'hwb'], true)
            && ($working[0] === null || $working[1] === null || $working[2] === null);

        if (
            ! $powerlessHue
            && ! $legacyMissingCollapse
            && $this->areChannelsUnchanged($beforeMapping, $working)
            && $destSpace !== 'hwb'
        ) {
            return $color;
        }

        if ($powerlessHue) {
            if ($destSpace !== $srcSpace) {
                $working = $this->dartMath->convertNumeric(
                    $destSpace,
                    $srcSpace,
                    [
                        $working[0] ?? 0.0,
                        $working[1] ?? 0.0,
                        $working[2] ?? 0.0,
                    ],
                );
            }

            $working = [$working[0], $working[1], null];

            return $this->functionEvaluator?->serializeModifiedColor($color, $originalSpace, $working, $alpha) ?? $color;
        }

        if ($destSpace !== $originalSpace) {
            $working = $this->dartMath->convert(
                $destSpace,
                $originalSpace,
                $working,
            );

            if (in_array($originalSpace, ['rgb', 'hsl', 'hwb'], true)) {
                $working = [$working[0] ?? 0.0, $working[1] ?? 0.0, $working[2] ?? 0.0];
            }

            if (($originalSpace === 'lch' || $originalSpace === 'oklch')
                && $working[1] !== null
                && $this->dartMath->fuzzyEquals($working[1], 0.0)
            ) {
                $working[2] = null;
            }
        }

        return $this->functionEvaluator?->serializeModifiedColor($color, $originalSpace, $working, $alpha) ?? $color;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float}|null  [r, g, b in 0-255 unclamped, alpha]
     */
    public function extractLegacySrgbChannelsUnclamped(FunctionNode $color): ?array
    {
        $name = strtolower($color->name);

        if ($name === 'hsl' || $name === 'hsla') {
            if ($color->originSrgbChannels !== null) {
                $exact = $color->originSrgbChannels;

                return [
                    $exact[0] * 255.0,
                    $exact[1] * 255.0,
                    $exact[2] * 255.0,
                    $this->converter->toAlpha($color),
                ];
            }

            [$r, $g, $b] = $this->dartMath->convert('hsl', 'rgb', $this->extractRawHslChannels($color));

            $alpha = $this->converter->toAlpha($color);

            return [(float) $r, (float) $g, (float) $b, $alpha];
        }

        if ($name === 'hwb') {
            [$r, $g, $b] = $this->dartMath->convert('hwb', 'rgb', $this->extractRawHwbChannels($color));

            $alpha = $this->converter->toAlpha($color);

            return [(float) $r, (float) $g, (float) $b, $alpha];
        }

        if ($name === 'rgb' || $name === 'rgba') {
            return $this->extractUnclampedRgbChannels($color);
        }

        return null;
    }

    public function isColorInGamut(AstNode $color, ?string $space): bool
    {
        $target = $this->normalizeGamutSpace(
            $space ?? $this->converter->detectNativeColorSpace($color),
        );

        if (in_array($target, self::UNBOUNDED_GAMUT_SPACES, true)) {
            return true;
        }

        $gamutSpace = match ($target) {
            'rgb',
            'hsl',
            'hwb'   => 'rgb',
            default => $target,
        };

        [$srcSpace, $srcChannels] = $this->extractToGamutSourceChannels($color);

        $channels = $srcSpace === $gamutSpace
            ? $srcChannels
            : $this->dartMath->convert(
                $srcSpace === 'xyz' ? 'xyz-d65' : $srcSpace,
                $gamutSpace,
                $srcChannels,
            );

        return $this->areChannelsInGamut($gamutSpace, $channels);
    }

    private function hasMissingChannelNode(FunctionNode $color): bool
    {
        foreach ($this->converter->extractChannelNodes($color) as $node) {
            if ($node instanceof NumberNode) {
                continue;
            }

            if ($node instanceof StringNode && AstValueInspector::isNoneKeyword($node)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function extractUnclampedSrgbChannels(AstNode $color, bool $missingAsZero = true): array
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'color') {
            $native = $this->extractGenericSpaceAndChannels($color);

            if ($native !== null) {
                [$inputSpace, $raw] = $native;

                $converted = $missingAsZero
                    ? $this->dartMath->convert($inputSpace, 'rgb', $raw)
                    : $this->dartMath->convertNumeric($inputSpace, 'rgb', $raw);

                return [
                    $converted[0] ?? 0.0,
                    $converted[1] ?? 0.0,
                    $converted[2] ?? 0.0,
                ];
            }
        }

        if ($color instanceof FunctionNode) {
            $nativeChannels = $this->extractNativeLabLchOklabOklchUnclamped($color);

            if ($nativeChannels !== null) {
                return $nativeChannels;
            }
        }

        if ($color instanceof FunctionNode) {
            $legacyChannels = $this->extractLegacySrgbChannelsUnclamped($color);

            if ($legacyChannels !== null) {
                return [$legacyChannels[0], $legacyChannels[1], $legacyChannels[2]];
            }
        }

        // Fallback for ColorNode/StringNode
        $rgb = $this->converter->toRgb($color);

        return [$rgb->rValue(), $rgb->gValue(), $rgb->bValue()];
    }

    /**
     * @param ChannelVector $left
     * @param ChannelVector $right
     */
    private function areChannelsUnchanged(array $left, array $right): bool
    {
        foreach ([0, 1, 2] as $i) {
            /** @var float|null $leftValue */
            $leftValue = $left[$i] ?? null;

            /** @var float|null $rightValue */
            $rightValue = $right[$i] ?? null;

            if ($leftValue === null || $rightValue === null) {
                if ($leftValue !== null || $rightValue !== null) {
                    return false;
                }

                continue;
            }

            if (! $this->dartMath->fuzzyEquals($leftValue, $rightValue)) {
                return false;
            }
        }

        return true;
    }

    private function normalizeGamutSpace(string $space): string
    {
        return $space === 'xyz-d65' ? 'xyz' : $space;
    }

    private function isOutOfGamutColorMixNode(?AstNode $node): bool
    {
        if (! ($node instanceof FunctionNode) || strtolower($node->name) !== 'color-mix') {
            return false;
        }

        $arguments = $node->arguments;

        $spaceMarker    = ($arguments[0] ?? null) instanceof ListNode ? ($arguments[0]->items[0] ?? null) : null;
        $targetSpace    = ($arguments[0] ?? null) instanceof ListNode ? ($arguments[0]->items[1] ?? null) : null;
        $colorNode      = ($arguments[1] ?? null) instanceof ListNode ? ($arguments[1]->items[0] ?? null) : null;
        $percentageNode = ($arguments[1] ?? null) instanceof ListNode ? ($arguments[1]->items[1] ?? null) : null;

        if (
            count($arguments) !== 3
            || ! ($arguments[0] instanceof ListNode)
            || count($arguments[0]->items) !== 2
            || ! ($spaceMarker instanceof StringNode)
            || strtolower($spaceMarker->value) !== 'in'
            || ! ($targetSpace instanceof StringNode)
            || ! in_array(strtolower($targetSpace->value), self::UNBOUNDED_GAMUT_SPACES, true)
            || ! ($arguments[1] instanceof ListNode)
            || count($arguments[1]->items) !== 2
            || ! ($colorNode instanceof FunctionNode)
            || strtolower($colorNode->name) !== 'color'
            || ! ($percentageNode instanceof NumberNode)
            || (float) $percentageNode->value !== 100.0
            || ! ($arguments[2] instanceof StringNode)
        ) {
            return false;
        }

        return true;
    }

    /**
     * @return array{0: string, 1: array{0: float|null, 1: float|null, 2: float|null}}
     */
    private function extractToGamutSourceChannels(AstNode $color): array
    {
        if ($color instanceof FunctionNode) {
            $origin = $color->originColorSpace;

            if (($origin === 'rgb' || $origin === 'hwb') && in_array(strtolower($color->name), ['hsl', 'hsla'], true)) {
                $raw = $this->extractRawHslChannels($color);

                return [
                    $origin,
                    $this->dartMath->convertNumeric('hsl', $origin, [
                        $raw[0] ?? 0.0,
                        $raw[1] ?? 0.0,
                        $raw[2] ?? 0.0,
                    ]),
                ];
            }

            $native = $this->extractNativeSpaceAndChannels($color);

            if ($native !== null) {
                [$space, $channels] = $native;

                return [
                    $space === 'xyz-d65' ? 'xyz' : $space,
                    [$channels[0] ?? null, $channels[1] ?? null, $channels[2] ?? null],
                ];
            }
        }

        $rgb = $this->converter->toRgb($color);

        return ['rgb', [$rgb->rValue(), $rgb->gValue(), $rgb->bValue()]];
    }

    /**
     * @param ChannelVector $channels
     * @return ChannelVector
     */
    private function clipToGamutRange(string $space, array $channels): array
    {
        $max = match ($space) {
            'rgb'        => 255.0,
            'hsl', 'hwb' => 100.0,
            default      => 1.0,
        };

        foreach ($channels as $i => $channel) {
            if ($channel === null) {
                continue;
            }

            $channels[$i] = min(max($channel, 0.0), $max);
        }

        return $channels;
    }

    /**
     * @param ChannelVector $channels
     * @return ChannelVector
     */
    private function applyLocalMindeGamutMapping(string $space, array $channels): array
    {
        $destDart = $space === 'xyz' ? 'xyz-d65' : $space;

        if ($this->areChannelsInGamut($space, $channels)) {
            return $channels;
        }

        $numeric = [
            $channels[0] ?? 0.0,
            $channels[1] ?? 0.0,
            $channels[2] ?? 0.0,
        ];

        $originOklch = $this->dartMath->convertNumeric($destDart, 'oklch', $numeric);

        [$lightness, , $hue] = $originOklch;

        if ($lightness >= 1.0 || $this->dartMath->fuzzyEquals($lightness, 1.0)) {
            return match ($space) {
                'hsl'   => [null, 0.0, 100.0],
                'hwb'   => [null, 100.0, 0.0],
                'rgb'   => [255.0, 255.0, 255.0],
                default => [1.0, 1.0, 1.0],
            };
        }

        if ($lightness <= 0.0 || $this->dartMath->fuzzyEquals($lightness, 0.0)) {
            return match ($space) {
                'hsl'   => [null, 0.0, 0.0],
                'hwb'   => [null, 0.0, 100.0],
                default => [0.0, 0.0, 0.0],
            };
        }

        $clipped = $this->clipToGamutRange($space, $channels);

        if ($this->deltaEOk($space, $clipped, $numeric) < self::LOCAL_MINDE_JND) {
            return $clipped;
        }

        $minChroma  = 0.0;
        $maxChroma  = max(0.0, $originOklch[1]);
        $minInGamut = true;

        while ($maxChroma - $minChroma > self::LOCAL_MINDE_EPSILON) {
            $chroma = ($minChroma + $maxChroma) / 2.0;

            $current = $this->dartMath->convertNumeric('oklch', $destDart, [
                $lightness,
                $chroma,
                $hue,
            ]);

            if ($minInGamut && $this->areChannelsInGamut($space, $current)) {
                $minChroma = $chroma;

                continue;
            }

            $clipped = $this->clipToGamutRange($space, $current);
            $deltaE  = $this->deltaEOk($space, $clipped, $current);

            if ($deltaE < self::LOCAL_MINDE_JND) {
                if (self::LOCAL_MINDE_JND - $deltaE < self::LOCAL_MINDE_EPSILON) {
                    return $clipped;
                }

                $minInGamut = false;
                $minChroma  = $chroma;
            } else {
                $maxChroma = $chroma;
            }
        }

        return $clipped;
    }

    /**
     * @param ChannelVector $channels
     */
    private function areChannelsInGamut(string $space, array $channels): bool
    {
        $max = match ($space) {
            'rgb'        => 255.0,
            'hsl', 'hwb' => 100.0,
            default      => 1.0,
        };

        foreach ($channels as $channel) {
            if ($channel === null) {
                continue;
            }

            if (! $this->dartMath->fuzzyEquals($channel, 0.0) && ! $this->dartMath->fuzzyEquals($channel, $max)
                && ($channel < 0.0 || $channel > $max)
            ) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param ChannelVector $channels1
     * @param array{0: float, 1: float, 2: float} $channels2
     */
    private function deltaEOk(string $space, array $channels1, array $channels2): float
    {
        $destDart = $space === 'xyz' ? 'xyz-d65' : $space;

        $lab1 = $this->dartMath->convertNumeric($destDart, 'oklab', [
            $channels1[0] ?? 0.0,
            $channels1[1] ?? 0.0,
            $channels1[2] ?? 0.0,
        ]);

        $lab2 = $this->dartMath->convertNumeric($destDart, 'oklab', $channels2);

        $d0 = $lab1[0] - $lab2[0];
        $d1 = $lab1[1] - $lab2[1];
        $d2 = $lab1[2] - $lab2[2];

        return sqrt($d0 * $d0 + $d1 * $d1 + $d2 * $d2);
    }

    private function hasAllChannelsMissing(AstNode $color): bool
    {
        if (! ($color instanceof FunctionNode)) {
            return false;
        }

        $channels = $this->converter->extractChannelNodes($color);

        $offset = strtolower($color->name) === 'color' ? 1 : 0;

        for ($i = 0; $i < 3; $i++) {
            if (! $this->runtime->argumentParser->isMissingChannelNode($channels[$i + $offset] ?? new StringNode('none'))) {
                return false;
            }
        }

        return true;
    }

    private function hasMissingHwbNonHueChannels(FunctionNode $color): bool
    {
        $arguments = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

        return isset($arguments[1], $arguments[2])
            && $this->runtime->argumentParser->isMissingChannelNode($arguments[1])
            && $this->runtime->argumentParser->isMissingChannelNode($arguments[2]);
    }

    /** @return array{hue: bool, white: bool, black: bool} */
    private function extractMissingHwbChannels(FunctionNode $color): array
    {
        $arguments = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
        $none      = new StringNode('none');

        return [
            'hue'   => $this->runtime->argumentParser->isMissingChannelNode($arguments[0] ?? $none),
            'white' => $this->runtime->argumentParser->isMissingChannelNode($arguments[1] ?? $none),
            'black' => $this->runtime->argumentParser->isMissingChannelNode($arguments[2] ?? $none),
        ];
    }

    private function toXyzD65GenericSpace(AstNode $color, string $space): AstNode
    {
        $alpha = $this->converter->toAlpha($color);

        [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color);

        $resultChannels = $this->dartMath->convertNumeric(
            'srgb',
            $space,
            [$r / 255.0, $g / 255.0, $b / 255.0],
        );

        return $this->buildRgbFamilyOutput($space, $resultChannels, $alpha);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $resultChannels
     */
    private function buildRgbFamilyOutput(string $targetSpace, array $resultChannels, float $alpha): AstNode
    {
        $outputNodes = [new StringNode($targetSpace)];

        for ($i = 0; $i < 3; $i++) {
            $outputNodes[] = new NumberNode($resultChannels[$i]);
        }

        return $this->converter->buildFunctionalColorNode('color', $outputNodes, $alpha);
    }


    private function toXyzD50GenericSpace(AstNode $color, string $space): AstNode
    {
        $alpha = $this->converter->toAlpha($color);

        $xyzD50           = $this->converter->toXyzD50($color);
        $prophotoChannels = $this->encodeProphotoChannels(
            $this->runtime->spaceConverter->xyzD50ToLinProphoto($xyzD50),
        );

        return $this->converter->buildFunctionalColorNode('color', [
            new StringNode($space),
            new NumberNode($prophotoChannels[0]),
            new NumberNode($prophotoChannels[1]),
            new NumberNode($prophotoChannels[2]),
        ], $alpha);
    }

    /**
     * @param array{0: float, 1: float, 2: float} $channels
     * @return array{0: float, 1: float, 2: float}
     */
    private function encodeProphotoChannels(array $channels): array
    {
        $converter = $this->runtime->spaceConverter;

        return [
            $converter->gamProphoto($channels[0]),
            $converter->gamProphoto($channels[1]),
            $converter->gamProphoto($channels[2]),
        ];
    }

    /**
     * @param array{0: float, 1: float, 2: float, 3: float} $channels
     */
    private function isOutOfGamutLegacyChannels(array $channels): bool
    {
        return $channels[0] < 0.0 || $channels[0] > 255.0
            || $channels[1] < 0.0 || $channels[1] > 255.0
            || $channels[2] < 0.0 || $channels[2] > 255.0;
    }

    /**
     * @return array{0: float, 1: float, 2: float, 3: float} [r, g, b in 0-255 unclamped, alpha]
     */
    private function extractUnclampedRgbChannels(FunctionNode $color): array
    {
        $ap = $this->runtime->argumentParser;

        /** @var array<int, AstNode> $expandedArgs */
        $expandedArgs = $ap->expandSingleSpaceListArgument($color->arguments);

        $values = [];

        foreach ([0, 1, 2] as $i) {
            $node = $expandedArgs[$i] ?? null;

            if ($node instanceof NumberNode && ! $ap->isMissingChannelNode($node)) {
                $val = (float) $node->value;

                $values[$i] = strtolower($node->unit ?? '') === '%' ? $val * 255.0 / 100.0 : $val;
            } else {
                $values[$i] = 0.0;
            }
        }

        return [$values[0], $values[1], $values[2], $this->converter->toAlpha($color)];
    }

    /**
     * @return array{0: float, 1: float, 2: float}|null
     */
    private function extractNativeLabLchOklabOklchUnclamped(FunctionNode $color): ?array
    {
        $name = strtolower($color->name);

        if (! in_array($name, ['lab', 'laba', 'lch', 'lcha', 'oklab', 'oklaba', 'oklch', 'oklcha'], true)) {
            return null;
        }

        $channels = match ($name) {
            'lab', 'laba'     => $this->extractRawLabChannels($color),
            'lch', 'lcha'     => $this->extractRawLchChannels($color),
            'oklab', 'oklaba' => $this->extractRawOklabChannels($color),
            default           => $this->extractRawOklchChannels($color),
        };

        $space = in_array($name, ['lab', 'laba'], true)
            ? 'lab'
            : (in_array($name, ['lch', 'lcha'], true) ? 'lch' : (in_array($name, ['oklab', 'oklaba'], true) ? 'oklab' : 'oklch'));

        [$r, $g, $b] = $this->dartMath->convert($space, 'rgb', $channels);

        return [(float) $r, (float) $g, (float) $b];
    }

    private function buildColorMixNode(string $space, XyzColor $xyz, float $alpha): FunctionNode
    {
        $xyzNode = $this->converter->buildGenericColorFunctionNode('xyz', [
            (float) $xyz->x,
            (float) $xyz->y,
            (float) $xyz->z,
        ], $alpha);

        return new FunctionNode('color-mix', [
            new ListNode([
                new StringNode('in'),
                new StringNode($space),
            ], 'space'),
            new ListNode([
                $xyzNode,
                new NumberNode(100, '%'),
            ], 'space'),
            new StringNode('black'),
        ]);
    }

    private function extractXyzD65ForColorMix(FunctionNode $color, string $destSpace): XyzColor
    {
        [$srcSpace, $channels] = $this->extractNativeSpaceAndChannels($color) ?? ['', [0.0, 0.0, 0.0]];

        if ($srcSpace === 'hsl' && $color->originSrgbChannels !== null) {
            $srcSpace = 'srgb';
            $channels = $color->originSrgbChannels;
        }

        $converted = $srcSpace === $destSpace
            ? $channels
            : $this->dartMath->convert($srcSpace, $destSpace, $channels);

        $xyzChannels = $this->dartMath->convert($destSpace, 'xyz-d65', $converted);

        return new XyzColor(
            x: (float) $xyzChannels[0],
            y: (float) $xyzChannels[1],
            z: (float) $xyzChannels[2],
        );
    }

    /**
     * @param ChannelVector $channels
     */
    private function buildGenericColorNodePreservingMissing(string $space, array $channels, float $alpha): AstNode
    {
        $nodes = [new StringNode($space)];

        for ($i = 0; $i < 3; $i++) {
            $nodes[] = $channels[$i] === null
                ? new StringNode('none')
                : new NumberNode($channels[$i]);
        }

        return $this->converter->buildFunctionalColorNode('color', $nodes, $alpha);
    }

    /**
     * @return ChannelVector|null
     */
    private function dartConvertChannelsNullable(FunctionNode $color, string $dest): ?array
    {
        $native = $this->extractNativeSpaceAndChannels($color);

        if ($native === null) {
            return null;
        }

        [$space, $channels] = $native;

        if (
            $space === 'hsl'
            && $color->originSrgbChannels !== null
            && ! in_array($dest, ['rgb', 'hsl', 'hwb'], true)
        ) {
            $space    = 'srgb';
            $channels = $color->originSrgbChannels;
        }

        if ($space === $dest) {
            return [$channels[0], $channels[1], $channels[2]];
        }

        return $this->dartMath->convert($space, $dest, $channels);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    private function dartConvertChannels(FunctionNode $color, string $dest): array
    {
        $converted = $this->dartConvertChannelsNullable($color, $dest);

        return [($converted[0] ?? 0.0), ($converted[1] ?? 0.0), ($converted[2] ?? 0.0)];
    }

    /**
     * @return array{0: string, 1: ChannelVector}|null
     */
    private function extractNativeSpaceAndChannels(FunctionNode $color): ?array
    {
        $name = strtolower($color->name);

        return match ($name) {
            'hsl', 'hsla'     => ['hsl', $this->extractRawHslChannels($color)],
            'hwb', 'hwba'     => ['hwb', $this->extractRawHwbChannels($color)],
            'rgb', 'rgba'     => ['rgb', [
                $this->rawChannel($color, 0, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 255.0 / 100.0 : null)),
                $this->rawChannel($color, 1, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 255.0 / 100.0 : null)),
                $this->rawChannel($color, 2, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 255.0 / 100.0 : null)),
            ]],
            'lab', 'laba'     => ['lab', $this->extractRawLabChannels($color)],
            'lch', 'lcha'     => ['lch', $this->extractRawLchChannels($color)],
            'oklab', 'oklaba' => ['oklab', $this->extractRawOklabChannels($color)],
            'oklch', 'oklcha' => ['oklch', $this->extractRawOklchChannels($color)],
            'color'           => $this->extractGenericSpaceAndChannels($color),
            default           => null,
        };
    }

    /**
     * @return ChannelVector
     */
    private function extractRawHslChannels(FunctionNode $color): array
    {
        return [
            $this->rawChannel($color, 0, fn(float $v, string $unit): ?float => $this->hueToDegrees($v, $unit)),
            $this->rawChannel($color, 1, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v : null),
            $this->rawChannel($color, 2, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v : null),
        ];
    }

    /**
     * @return ChannelVector
     */
    private function extractRawHwbChannels(FunctionNode $color): array
    {
        return [
            $this->rawChannel($color, 0, fn(float $v, string $unit): ?float => $this->hueToDegrees($v, $unit)),
            $this->rawChannel($color, 1, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v : null),
            $this->rawChannel($color, 2, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v : null),
        ];
    }

    /**
     * @return ChannelVector
     */
    private function extractRawLabChannels(FunctionNode $color): array
    {
        return [
            $this->rawChannel($color, 0, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v : null),
            $this->rawChannel($color, 1, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 125.0 / 100.0 : null)),
            $this->rawChannel($color, 2, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 125.0 / 100.0 : null)),
        ];
    }

    /**
     * @return ChannelVector
     */
    private function extractRawLchChannels(FunctionNode $color): array
    {
        return [
            $this->rawChannel($color, 0, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v : null),
            $this->rawChannel($color, 1, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 150.0 / 100.0 : null)),
            $this->rawChannel($color, 2, fn(float $v, string $unit): ?float => $this->hueToDegrees($v, $unit)),
        ];
    }

    /**
     * @return ChannelVector
     */
    private function extractRawOklabChannels(FunctionNode $color): array
    {
        return [
            $this->rawChannel($color, 0, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v / 100.0 : null),
            $this->rawChannel($color, 1, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 0.4 / 100.0 : null)),
            $this->rawChannel($color, 2, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 0.4 / 100.0 : null)),
        ];
    }

    /**
     * @return ChannelVector
     */
    private function extractRawOklchChannels(FunctionNode $color): array
    {
        return [
            $this->rawChannel($color, 0, fn(float $v, string $unit): ?float => $unit === '' || $unit === '%' ? $v / 100.0 : null),
            $this->rawChannel($color, 1, fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v * 150.0 / 100.0 / 100.0 : null)),
            $this->rawChannel($color, 2, fn(float $v, string $unit): ?float => $this->hueToDegrees($v, $unit)),
        ];
    }

    /**
     * @return array{0: string, 1: ChannelVector}|null
     */
    private function extractGenericSpaceAndChannels(FunctionNode $color): ?array
    {
        $space = $this->converter->detectGenericColorSpace($color);

        if (! in_array($space, ['srgb', 'srgb-linear', 'display-p3', 'display-p3-linear', 'a98-rgb', 'rec2020', 'prophoto-rgb', 'xyz', 'xyz-d50'], true)) {
            return null;
        }

        $canonicalSpace = $space === 'xyz' ? 'xyz-d65' : $space;
        $channels       = $this->converter->extractChannelNodes($color);
        $transform      = fn(float $v, string $unit): ?float => $unit === '' ? $v : ($unit === '%' ? $v / 100.0 : null);

        return [
            $canonicalSpace,
            [
                $this->rawChannel($color, 1, $transform, $channels),
                $this->rawChannel($color, 2, $transform, $channels),
                $this->rawChannel($color, 3, $transform, $channels),
            ],
        ];
    }

    /**
     * @param array<int, AstNode>|null $precomputedChannels
     */
    private function rawChannel(FunctionNode $color, int $index, callable $transform, ?array $precomputedChannels = null): ?float
    {
        $ap   = $this->runtime->argumentParser;
        $node = $precomputedChannels !== null
            ? ($precomputedChannels[$index] ?? null)
            : ($ap->expandSingleSpaceListArgument($color->arguments)[$index] ?? null);

        if (! ($node instanceof NumberNode) || $ap->isMissingChannelNode($node)) {
            return null;
        }

        /** @var callable(float, string): ?float $transform */
        return $transform((float) $node->value, strtolower($node->unit ?? ''));
    }

    private function hueToDegrees(float $value, string $unit): ?float
    {
        return match ($unit) {
            '', 'deg'  => $value,
            'rad'      => $value * 180.0 / M_PI,
            'grad'     => $value * 0.9,
            'turn'     => $value * 360.0,
            default    => null,
        };
    }

    private function isLightnessOutOfRange(FunctionNode $color, string $nativeSpace): bool
    {
        $ap = $this->runtime->argumentParser;

        $expandedArgs  = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
        [$channels]    = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);
        $lightnessNode = $channels[0] ?? null;

        if ($lightnessNode === null || $ap->isMissingChannelNode($lightnessNode)) {
            return false;
        }

        $lightness = match (true) {
            in_array($nativeSpace, ['oklch', 'oklcha', 'oklab', 'oklaba'], true),
            in_array($nativeSpace, ['lch', 'lcha', 'lab', 'laba'], true) => $ap->asPercentage($lightnessNode, 'to-space'),
            default => 50.0,
        };

        return $lightness < -self::LIGHTNESS_BOUNDARY_EPSILON || $lightness > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON;
    }

    private function convertToLch(AstNode $color): AstNode
    {
        $alpha   = $this->converter->toAlpha($color);
        $dartLch = $color instanceof FunctionNode ? $this->dartConvertChannels($color, 'lch') : null;
        $lch     = $dartLch !== null
            ? new LchColor(l: $dartLch[0], c: $dartLch[1], h: $dartLch[2])
            : $this->runtime->spaceConverter->xyzD50ToLch($this->converter->toXyzD50($color));

        $isLabOklabSource = $color instanceof FunctionNode
            && in_array(strtolower($color->name), ['lab', 'oklab'], true);

        $lightnessNode = new NumberNode($lch->lValue(), '%');

        if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
            $oklch = $this->extractOklchMixData($color);
            $lch   = $this->runtime->spaceConverter->oklchToLch(new OklchColor(
                l: $oklch->l,
                c: $oklch->c,
                h: $oklch->h,
                a: $oklch->a,
            ));

            if (
                ! $oklch->lightnessMissing && ($lch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $lch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('lch', $this->extractXyzD65ForColorMix($color, 'lch'), $oklch->a);
            }

            return $this->buildMissingAwarePolarColorNode(
                'lch',
                $lch->lValue(),
                $lch->cValue(),
                $lch->hValue(),
                $oklch,
            );
        }

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($lch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
            || $lch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
        ) {
            return $this->buildColorMixNode('lch', $this->extractXyzD65ForColorMix($color, 'lch'), $alpha);
        }

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($legacyUnclamped = $this->extractLegacySrgbChannelsUnclamped($color)) !== null
        ) {
            $dartLchDest = $this->dartConvertChannels($color, 'lch');

            $unclampedLch = new LchColor(l: $dartLchDest[0], c: $dartLchDest[1], h: $dartLchDest[2]);

            if ($this->isOutOfGamutLegacyChannels($legacyUnclamped)) {
                return $this->converter->buildLchColorNode($unclampedLch, $this->converter->toAlpha($color));
            }
        }

        if ($this->isSemanticChannelMissing($color)) {
            $lightnessNode = $this->missingStringNode();
        }

        [$chromaNode, $hueNode, $hslWithMissing] = $this->buildChromaAndHueNodesForColor($color, $lch, $isLabOklabSource);

        if (! $isLabOklabSource && (($hslWithMissing !== null && $hslWithMissing->h === null) || abs($lch->cValue()) < 0.0000001)) {
            $hueNode = new StringNode('none');
        }

        if ($color instanceof FunctionNode && strtolower($color->name) === 'hwb') {
            $missing = $this->extractMissingHwbChannels($color);

            if ($missing['hue']) {
                $hueNode = $this->missingStringNode();
            }

            if ($missing['white'] && $missing['black']) {
                return $this->converter->buildFunctionalColorNode('lch', [
                    $this->missingStringNode(),
                    $this->missingStringNode(),
                    new NumberNode($lch->hValue(), 'deg'),
                ], $alpha);
            }
        }

        return $this->converter->buildFunctionalColorNode('lch', [
            $lightnessNode,
            $chromaNode,
            $hueNode,
        ], $alpha);
    }

    private function convertToOklch(AstNode $color): AstNode
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $lchData  = $this->extractLchMissingData($color);
            $lchColor = $this->runtime->spaceConverter->xyzD65ToOklch(
                $this->runtime->spaceConverter->lchToXyzD65($lchData->l, $lchData->c, $lchData->h),
            );

            if (
                ! $lchData->lightnessMissing && ($lchColor->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $lchColor->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('oklch', $this->extractXyzD65ForColorMix($color, 'oklch'), $lchData->a);
            }

            return $this->buildMissingAwarePolarColorNode(
                'oklch',
                $lchColor->lValue(),
                $lchColor->cValue(),
                $lchColor->hValue(),
                $lchData,
            );
        }

        $dartOklch = $color instanceof FunctionNode ? $this->dartConvertChannels($color, 'oklch') : null;

        $oklch = $dartOklch !== null
            ? new OklchColor(l: $dartOklch[0] * 100.0, c: $dartOklch[1], h: $dartOklch[2], a: $this->converter->toAlpha($color))
            : $this->toOklchPreservingMissingChannels($color);

        $isLabOklabSource = $color instanceof FunctionNode
            && in_array(strtolower($color->name), ['lab', 'oklab'], true);

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($oklch->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
            || $oklch->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
        ) {
            return $this->buildColorMixNode('oklch', $this->extractXyzD65ForColorMix($color, 'oklch'), $oklch->a);
        }

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($legacyUnclamped = $this->extractLegacySrgbChannelsUnclamped($color)) !== null
        ) {
            $dartOklchDest = $this->dartConvertChannels($color, 'oklch');

            if ($this->isOutOfGamutLegacyChannels($legacyUnclamped)) {
                $unclampedAlpha = $this->converter->toAlpha($color);
                $unclampedOklch = new OklchColor(l: $dartOklchDest[0] * 100.0, c: $dartOklchDest[1], h: $dartOklchDest[2], a: $unclampedAlpha);

                return $this->converter->buildFunctionalColorNode('oklch', [
                    new NumberNode($unclampedOklch->lValue(), '%'),
                    new NumberNode($unclampedOklch->cValue()),
                    new NumberNode($unclampedOklch->hValue(), 'deg'),
                ], $unclampedOklch->a);
            }
        }

        $lightnessNode = new NumberNode($oklch->lValue(), '%');

        if ($this->isSemanticChannelMissing($color)) {
            $lightnessNode = $this->missingStringNode();
        }

        [$chromaNode, $hueNode, $hslWithMissing] = $this->buildChromaAndHueNodesForColor($color, $oklch, $isLabOklabSource);

        if ($color instanceof FunctionNode && strtolower($color->name) === 'hwb') {
            $missing = $this->extractMissingHwbChannels($color);

            if ($missing['hue']) {
                $hueNode = $this->missingStringNode();
            }

            if ($missing['white'] && $missing['black']) {
                return $this->converter->buildFunctionalColorNode('oklch', [
                    $this->missingStringNode(),
                    $this->missingStringNode(),
                    new NumberNode($oklch->hValue(), 'deg'),
                ], $oklch->a);
            }
        }

        if (! $isLabOklabSource && $hslWithMissing !== null && ($hslWithMissing->h === null || $hslWithMissing->s === null)) {
            $hueNode = $this->missingStringNode();
        }

        return $this->converter->buildFunctionalColorNode('oklch', [
            $lightnessNode,
            $chromaNode,
            $hueNode,
        ], $oklch->a);
    }

    private function convertToLab(AstNode $color): AstNode
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $lchData = $this->extractLchMissingData($color);

            if ($lchData->lightnessMissing) {
                return $this->converter->buildFunctionalColorNode('lab', [
                    $this->missingStringNode(),
                    $this->missingStringNode(),
                    $this->missingStringNode(),
                ], $lchData->a);
            }

            $hueRadians = $lchData->h * M_PI / 180.0;
            $aChannel   = $lchData->c * cos($hueRadians);
            $bChannel   = $lchData->c * sin($hueRadians);

            if ($lchData->l < -self::LIGHTNESS_BOUNDARY_EPSILON || $lchData->l > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON) {
                return $this->buildColorMixNode('lab', $this->extractXyzD65ForColorMix($color, 'lab'), $lchData->a);
            }

            $chromaZero = abs($lchData->c) < 0.0000001;

            if ($chromaZero && ($lchData->hueMissing || abs($lchData->l) < 0.0000001)) {
                return $this->converter->buildFunctionalColorNode('lab', [
                    new NumberNode($lchData->l, '%'),
                    $this->missingStringNode(),
                    $this->missingStringNode(),
                ], $lchData->a);
            }

            return $this->converter->buildFunctionalColorNode('lab', [
                new NumberNode($lchData->l, '%'),
                new NumberNode($aChannel),
                new NumberNode($bChannel),
            ], $lchData->a);
        }

        if ($color instanceof FunctionNode && strtolower($color->name) === 'oklab') {
            [$lightnessMissing, $aMissing, $bMissing] = $this->extractMissingLabChannelFlags($color);

            $labColor = $this->runtime->spaceConverter->xyzD50ToLab(
                $this->converter->toXyzD50($color),
                $this->converter->toAlpha($color),
            );

            if (
                ! $lightnessMissing && ($labColor->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $labColor->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('lab', $this->extractXyzD65ForColorMix($color, 'lab'), $labColor->alpha);
            }

            $lightnessNode = $lightnessMissing
                ? $this->missingStringNode()
                : new NumberNode($labColor->lValue(), '%');

            $aNode = $aMissing
                ? $this->missingStringNode()
                : new NumberNode($labColor->aValue());

            $bNode = $bMissing
                ? $this->missingStringNode()
                : new NumberNode($labColor->bValue());

            return $this->converter->buildFunctionalColorNode('lab', [
                $lightnessNode,
                $aNode,
                $bNode,
            ], $labColor->alpha);
        }

        if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
            $oklch = $this->extractOklchMixData($color);

            $labColor = $this->runtime->spaceConverter->xyzD50ToLab(
                $this->converter->toXyzD50($color),
                $this->converter->toAlpha($color),
            );

            if ($oklch->hasMissingChannels()) {
                $lightnessNode = $oklch->lightnessMissing
                    ? $this->missingStringNode()
                    : new NumberNode($labColor->lValue(), '%');

                if ($oklch->chromaMissing && $oklch->hueMissing) {
                    $aNode = $this->missingStringNode();
                    $bNode = $this->missingStringNode();
                } elseif ($oklch->chromaMissing) {
                    $aNode = new NumberNode(0.0);
                    $bNode = new NumberNode(0.0);
                } else {
                    $aNode = new NumberNode($labColor->aValue());
                    $bNode = new NumberNode($labColor->bValue());
                }

                return $this->converter->buildFunctionalColorNode('lab', [
                    $lightnessNode,
                    $aNode,
                    $bNode,
                ], $oklch->a);
            }
        }

        $dartLab = $color instanceof FunctionNode ? $this->dartConvertChannels($color, 'lab') : null;

        if ($dartLab !== null) {
            $labColor = new LabColor(l: $dartLab[0], a: $dartLab[1], b: $dartLab[2], alpha: $this->converter->toAlpha($color));
        } else {
            $labColor = $this->runtime->spaceConverter->xyzD50ToLab(
                $this->converter->toXyzD50($color),
                $this->converter->toAlpha($color),
            );
        }

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($labColor->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
            || $labColor->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
        ) {
            return $this->buildColorMixNode('lab', $this->extractXyzD65ForColorMix($color, 'lab'), $labColor->alpha);
        }

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($legacyUnclamped = $this->extractLegacySrgbChannelsUnclamped($color)) !== null
        ) {
            $dartLabDest = $this->dartConvertChannels($color, 'lab');

            if ($this->isOutOfGamutLegacyChannels($legacyUnclamped)) {
                $unclampedLab = new LabColor(l: $dartLabDest[0], a: $dartLabDest[1], b: $dartLabDest[2], alpha: $this->converter->toAlpha($color));

                return $this->converter->buildLabColorNode($unclampedLab);
            }
        }

        if ($this->isSemanticChannelMissing($color)) {
            return $this->converter->buildFunctionalColorNode('lab', [
                $this->missingStringNode(),
                new NumberNode(0.0),
                new NumberNode(0.0),
            ], $labColor->alpha);
        }

        $hsl = $this->toHslWithMissingChannels($color);

        if ($hsl !== null && $hsl->h === null && $hsl->s === null) {
            return $this->converter->buildFunctionalColorNode('lab', [
                new NumberNode($labColor->lValue(), '%'),
                $this->missingStringNode(),
                $this->missingStringNode(),
            ], $labColor->alpha);
        }

        return $this->converter->buildLabColorNode($labColor);
    }

    private function buildMissingAwarePolarColorNode(
        string $space,
        float $lightness,
        float $chroma,
        float $hue,
        LchChannelData $data,
    ): AstNode {
        $lightnessNode = $data->lightnessMissing
            ? new StringNode('none')
            : new NumberNode($lightness, '%');

        $chromaNode = $data->chromaMissing
            ? new StringNode('none')
            : new NumberNode($chroma);

        $hueNode = $data->hueMissing || $data->chromaMissing || abs($chroma) < 0.0000001
            ? new StringNode('none')
            : new NumberNode($hue, 'deg');

        return $this->converter->buildFunctionalColorNode($space, [
            $lightnessNode,
            $chromaNode,
            $hueNode,
        ], $data->a);
    }

    /**
     * @return array{AstNode, AstNode, ?HslColor}
     */
    private function buildChromaAndHueNodesForColor(
        AstNode $color,
        LchColor|OklchColor $polarColor,
        bool $isLabOklabSource,
    ): array {
        $chroma = $polarColor->cValue();
        $hue    = $polarColor->hValue();
        $hsl = $this->toHslWithMissingChannels($color);
        $chromaNode = $this->buildChromaNode($hsl, $chroma);

        if ($color instanceof FunctionNode && $isLabOklabSource) {
            $source   = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
            $aMissing = isset($source[1]) && $this->runtime->argumentParser->isMissingChannelNode($source[1]);
            $bMissing = isset($source[2]) && $this->runtime->argumentParser->isMissingChannelNode($source[2]);

            [$chromaNode, $hueNode] = $this->buildChromaAndHueNodesForMissingLabChannels(
                $chroma,
                $hue,
                $aMissing && $bMissing,
            );
        } else {
            $hueNode = abs($chroma) < 0.0000001
                ? $this->missingStringNode()
                : new NumberNode($hue, 'deg');
        }

        return [$chromaNode, $hueNode, $hsl];
    }

    /**
     * @return array{AstNode, AstNode}
     */
    private function buildChromaAndHueNodesForMissingLabChannels(
        float $chroma,
        float $hue,
        bool $bothMissing,
    ): array {
        if ($bothMissing) {
            return [$this->missingStringNode(), $this->missingStringNode()];
        }

        return [
            new NumberNode($chroma),
            abs($chroma) < 0.0000001
                ? $this->missingStringNode()
                : new NumberNode($hue, 'deg'),
        ];
    }

    private function buildChromaNode(?HslColor $hsl, float $chroma): AstNode
    {
        return $hsl !== null && $hsl->s === null
            ? $this->missingStringNode()
            : new NumberNode($chroma);
    }

    /**
     * @return array{bool, bool, bool}
     */
    private function extractMissingLabChannelFlags(FunctionNode $color): array
    {
        $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);

        [$channels] = $this->runtime->arguments->splitChannelsAndAlpha($expandedArgs);

        return [
            isset($channels[0]) && $this->runtime->argumentParser->isMissingChannelNode($channels[0]),
            isset($channels[1]) && $this->runtime->argumentParser->isMissingChannelNode($channels[1]),
            isset($channels[2]) && $this->runtime->argumentParser->isMissingChannelNode($channels[2]),
        ];
    }

    private function isByteRgbInGamut(float $r, float $g, float $b): bool
    {
        return ($r > 0.0 || $this->dartMath->fuzzyEquals($r, 0.0)) && ($r < 255.0 || $this->dartMath->fuzzyEquals($r, 255.0))
            && ($g > 0.0 || $this->dartMath->fuzzyEquals($g, 0.0)) && ($g < 255.0 || $this->dartMath->fuzzyEquals($g, 255.0))
            && ($b > 0.0 || $this->dartMath->fuzzyEquals($b, 0.0)) && ($b < 255.0 || $this->dartMath->fuzzyEquals($b, 255.0));
    }

    private function convertToOklab(AstNode $color): AstNode
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
            $oklch = $this->extractOklchMixData($color);

            if ($oklch->hasMissingChannels()) {
                $lightnessNode = $oklch->lightnessMissing
                    ? $this->missingStringNode()
                    : new NumberNode($oklch->l, '%');

                if ($oklch->chromaMissing && $oklch->hueMissing) {
                    $aNode = $this->missingStringNode();
                    $bNode = $this->missingStringNode();
                } elseif ($oklch->chromaMissing) {
                    $aNode = new NumberNode(0.0);
                    $bNode = new NumberNode(0.0);
                } else {
                    $hue = deg2rad($oklch->h);
                    $aNode = new NumberNode($oklch->c * cos($hue));
                    $bNode = new NumberNode($oklch->c * sin($hue));
                }

                return $this->converter->buildFunctionalColorNode('oklab', [
                    $lightnessNode,
                    $aNode,
                    $bNode,
                ], $oklch->a);
            }
        }

        if ($color instanceof FunctionNode && strtolower($color->name) === 'lab') {
            [$lightnessMissing, $aMissing, $bMissing] = $this->extractMissingLabChannelFlags($color);

            $oklab = $this->runtime->spaceConverter->xyzD65ToOklab(
                $this->converter->toXyzD65($color),
                $this->converter->toAlpha($color),
            );

            if (
                ! $lightnessMissing && ($oklab->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
                || $oklab->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
            ) {
                return $this->buildColorMixNode('oklab', $this->extractXyzD65ForColorMix($color, 'oklab'), $oklab->alpha);
            }

            $lightnessNode = $lightnessMissing
                ? $this->missingStringNode()
                : new NumberNode($oklab->lValue(), '%');

            $aNode = $aMissing
                ? $this->missingStringNode()
                : new NumberNode($oklab->aValue());

            $bNode = $bMissing
                ? $this->missingStringNode()
                : new NumberNode($oklab->bValue());

            return $this->converter->buildFunctionalColorNode('oklab', [
                $lightnessNode,
                $aNode,
                $bNode,
            ], $oklab->alpha);
        }

        $dartOklab = $color instanceof FunctionNode ? $this->dartConvertChannels($color, 'oklab') : null;

        if ($dartOklab !== null) {
            $oklab = new OklabColor(l: $dartOklab[0] * 100.0, a: $dartOklab[1], b: $dartOklab[2], alpha: $this->converter->toAlpha($color));
        } else {
            $xyzD65 = $this->converter->toXyzD65($color);
            $oklab  = $this->runtime->spaceConverter->xyzD65ToOklab(
                $xyzD65,
                $this->converter->toAlpha($color),
            );
        }

        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $lchData = $this->extractLchMissingData($color);

            if ($lchData->hasMissingChannels()) {
                $lightnessNode = $lchData->lightnessMissing
                    ? $this->missingStringNode()
                    : new NumberNode($oklab->lValue(), '%');

                if ($lchData->chromaMissing && $lchData->hueMissing && ! $lchData->lightnessMissing) {
                    $aNode = $this->missingStringNode();
                    $bNode = $this->missingStringNode();
                } else {
                    $aNode = new NumberNode($oklab->aValue());
                    $bNode = new NumberNode($oklab->bValue());
                }

                return $this->converter->buildFunctionalColorNode('oklab', [
                    $lightnessNode,
                    $aNode,
                    $bNode,
                ], $oklab->alpha);
            }
        }

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($oklab->lValue() < -self::LIGHTNESS_BOUNDARY_EPSILON
            || $oklab->lValue() > 100.0 + self::LIGHTNESS_BOUNDARY_EPSILON)
        ) {
            return $this->buildColorMixNode('oklab', $this->extractXyzD65ForColorMix($color, 'oklab'), $oklab->alpha);
        }

        if (
            $color instanceof FunctionNode
            && ! $this->isSemanticChannelMissing($color)
            && ($legacyUnclamped = $this->extractLegacySrgbChannelsUnclamped($color)) !== null
        ) {
            $dartOklabDest = $this->dartConvertChannels($color, 'oklab');

            if ($this->isOutOfGamutLegacyChannels($legacyUnclamped)) {
                $unclampedAlpha = $this->converter->toAlpha($color);
                $unclampedOklab = new OklabColor(l: $dartOklabDest[0] * 100.0, a: $dartOklabDest[1], b: $dartOklabDest[2], alpha: $unclampedAlpha);

                return $this->converter->buildOklabColorNode($unclampedOklab);
            }
        }

        if ($this->isSemanticChannelMissing($color)) {
            return $this->converter->buildFunctionalColorNode('oklab', [
                $this->missingStringNode(),
                new NumberNode(0.0),
                new NumberNode(0.0),
            ], $oklab->alpha);
        }

        $hsl = $this->toHslWithMissingChannels($color);

        if ($hsl !== null && $hsl->h === null && $hsl->s === null) {
            return $this->converter->buildFunctionalColorNode('oklab', [
                new NumberNode($oklab->lValue(), '%'),
                $this->missingStringNode(),
                $this->missingStringNode(),
            ], $oklab->alpha);
        }

        return $this->converter->buildOklabColorNode($oklab);
    }

    private function convertToXyzD50Space(AstNode $color): AstNode
    {
        $dartXyz = $color instanceof FunctionNode ? $this->dartConvertChannelsNullable($color, 'xyz-d50') : null;

        if ($dartXyz !== null) {
            return $this->buildGenericColorNodePreservingMissing('xyz-d50', $dartXyz, $this->converter->toAlpha($color));
        }

        $xyz = $this->converter->toXyzD50($color);

        return $this->converter->buildGenericColorFunctionNode(
            'xyz-d50',
            [(float) $xyz->x, (float) $xyz->y, (float) $xyz->z],
            $this->converter->toAlpha($color),
        );
    }

    private function convertToXyzSpace(AstNode $color, string $space): AstNode
    {
        $dartXyz = $color instanceof FunctionNode ? $this->dartConvertChannelsNullable($color, 'xyz-d65') : null;

        if ($dartXyz !== null) {
            return $this->buildGenericColorNodePreservingMissing($space, $dartXyz, $this->converter->toAlpha($color));
        }

        $xyz = $this->converter->toXyzD65($color);

        return $this->converter->buildGenericColorFunctionNode(
            $space,
            [(float) $xyz->x, (float) $xyz->y, (float) $xyz->z],
            $this->converter->toAlpha($color),
        );
    }

    private function convertToRgbFamilyTarget(AstNode $color, string $space): AstNode
    {
        $dartGeneric = $color instanceof FunctionNode ? $this->dartConvertChannelsNullable($color, $space) : null;

        if ($dartGeneric !== null) {
            return $this->buildGenericColorNodePreservingMissing($space, $dartGeneric, $this->converter->toAlpha($color));
        }

        return $space === 'prophoto-rgb'
            ? $this->toXyzD50GenericSpace($color, $space)
            : $this->toXyzD65GenericSpace($color, $space);
    }

    private function convertToHslOrHwb(AstNode $color, string $space): AstNode
    {
        if (
            $space === 'hsl'
            && $color instanceof FunctionNode
            && in_array(strtolower($color->name), ['lab', 'oklab'], true)
        ) {
            $channels         = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
            $lightnessMissing = isset($channels[0])
                && $this->runtime->argumentParser->isMissingChannelNode($channels[0]);

            if ($lightnessMissing) {
                $hsl = $this->converter->serializeAsUnclampedHsl(...array_merge(
                    $this->extractUnclampedSrgbChannels($color, false),
                    [$this->converter->toAlpha($color), true],
                ));
                $hsl->arguments[2] = new NumberNode(0.0, '%');

                return $hsl;
            }
        }

        if (
            $space === 'hsl'
            && $color instanceof FunctionNode
            && in_array(strtolower($color->name), ['lch', 'oklch'], true)
        ) {
            $channels         = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
            $hueMissing       = isset($channels[2]) && $this->runtime->argumentParser->isMissingChannelNode($channels[2]);
            $lightnessMissing = isset($channels[0]) && $this->runtime->argumentParser->isMissingChannelNode($channels[0]);

            if ($hueMissing || $lightnessMissing) {
                $hsl = $this->converter->serializeAsUnclampedHsl(...array_merge(
                    $this->extractUnclampedSrgbChannels($color, false),
                    [$this->converter->toAlpha($color), true],
                ));

                if ($hsl->arguments !== []) {
                    $hsl->arguments[0] = $hueMissing ? new NumberNode(0.0) : $hsl->arguments[0];
                    $hsl->arguments[2] = $lightnessMissing ? new NumberNode(0.0, '%') : $hsl->arguments[2];
                }

                return $hsl;
            }
        }

        if (
            $space === 'hwb'
            && $color instanceof FunctionNode
            && in_array(strtolower($color->name), ['hsl', 'hsla'], true)
        ) {
            $hsl = $this->toHslWithMissingChannels($color);

            if ($hsl !== null && $hsl->h !== null && $hsl->s === null && $hsl->l === null) {
                return new ColorNode('red');
            }
        }

        if (
            $space === 'hsl'
            && $color instanceof FunctionNode
            && strtolower($color->name) === 'hwb'
        ) {
            $arguments = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
            $isMissing = fn(int $index): bool => isset($arguments[$index])
                && $this->runtime->argumentParser->isMissingChannelNode($arguments[$index]);

            if ($isMissing(1) && $isMissing(2)) {
                $hue = $isMissing(0)
                    ? 0.0
                    : $this->runtime->argumentParser->normalizeHue(
                        $this->runtime->argumentParser->asNumber($arguments[0], 'to-space'),
                    );

                return $this->converter->buildFunctionalColorNode('hsl', [
                    new NumberNode($hue),
                    new NumberNode(0.0, '%'),
                    new NumberNode(0.0, '%'),
                ], $this->converter->toAlpha($color));
            }
        }

        if (
            $space === 'hwb'
            && $color instanceof FunctionNode
            && strtolower($color->name) === 'hwb'
            && $this->hasMissingHwbNonHueChannels($color)
        ) {
            return $color;
        }

        if (
            $space === 'hwb'
            && $color instanceof FunctionNode
            && in_array(strtolower($color->name), ['lch', 'oklch'], true)
        ) {
            $channels      = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
            $firstMissing  = isset($channels[0]) && $this->runtime->argumentParser->isMissingChannelNode($channels[0]);
            $secondMissing = isset($channels[1]) && $this->runtime->argumentParser->isMissingChannelNode($channels[1]);
            $hueMissing    = isset($channels[2]) && $this->runtime->argumentParser->isMissingChannelNode($channels[2]);

            if ($firstMissing && $secondMissing) {
                return new ColorNode('red');
            }

            if ($hueMissing) {
                $hsl = $this->converter->serializeAsUnclampedHsl(...array_merge(
                    $this->extractUnclampedSrgbChannels($color, false),
                    [$this->converter->toAlpha($color), true],
                ));
                $hsl->arguments[0] = new NumberNode(0.0);

                return $hsl;
            }
        }

        if ($space === 'hwb' && $this->hasAllChannelsMissing($color)) {
            return new ColorNode('red');
        }

        $alpha = $this->converter->toAlpha($color);

        if ($space === 'hwb') {
            $dartHwb = $color instanceof FunctionNode ? $this->dartConvertChannelsNullable($color, 'hwb') : null;

            if ($dartHwb !== null) {
                [$r, $g, $b] = $this->dartMath->hwbToSrgb(
                    $dartHwb[0] ?? 0.0,
                    $dartHwb[1] ?? 0.0,
                    $dartHwb[2] ?? 0.0,
                );

                $r *= 255.0;
                $g *= 255.0;
                $b *= 255.0;

                if (! $this->isByteRgbInGamut($r, $g, $b)) {
                    return $this->converter->serializeAsUnclampedHsl($r, $g, $b, $alpha, true);
                }

                $byteRed   = (int) round($r);
                $byteGreen = (int) round($g);
                $byteBlue  = (int) round($b);
                $named     = $this->runtime->literalSerializer->findNamedColor($byteRed, $byteGreen, $byteBlue, $alpha);

                if (in_array($named, ['black', 'white', 'transparent'], true)) {
                    return new ColorNode($named);
                }

                return $this->converter->serializeAsUnclampedHsl($r, $g, $b, $alpha, true);
            }
        }

        [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color, false);

        if ($space === 'hwb') {
            $max = max($r, $g, $b);
            $min = min($r, $g, $b);

            if (abs($max) < 1e-10 && abs($min) < 1e-10) {
                return new ColorNode('black');
            }

            if (abs($max - 255.0) < 1e-10 && abs($min - 255.0) < 1e-10) {
                return new ColorNode('white');
            }

        }

        return $this->converter->serializeAsUnclampedHsl($r, $g, $b, $alpha, true);
    }

    private function convertToRgbOutput(AstNode $color): AstNode
    {
        if (
            $color instanceof FunctionNode
            && strtolower($color->name) === 'hwb'
            && $this->hasAllChannelsMissing($color)
        ) {
            return new ColorNode('black');
        }

        [$r, $g, $b] = $this->extractUnclampedSrgbChannels($color);

        $alpha = $this->converter->toAlpha($color);

        if (! $this->isByteRgbInGamut($r, $g, $b)) {
            return $this->converter->serializeAsUnclampedHsl($r, $g, $b, $alpha, true);
        }

        if ($color instanceof FunctionNode && $this->hasMissingChannelNode($color)) {
            $rgb = new RgbColor(r: $r, g: $g, b: $b, a: $alpha);
        } else {
            $rgb = $this->converter->toRgb($color);
        }

        return $this->converter->serializeByteRgb($rgb);
    }
}
