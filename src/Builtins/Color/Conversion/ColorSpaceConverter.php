<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Exceptions\UnsupportedColorSpace;
use Bugo\Iris\Operations\GamutMapper;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

use function abs;
use function count;
use function in_array;
use function strtolower;

final readonly class ColorSpaceConverter
{
    public function __construct(
        private ColorRuntime $runtime,
        private ColorNodeConverter $converter,
        private GamutMapper $gamutMapper = new GamutMapper(),
    ) {}

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
        ) {
            return $color;
        }

        if ($space === 'lch') {
            $xyz50 = $this->converter->toXyzD50($color);
            $lch   = $this->runtime->spaceConverter->xyzD50ToLch($xyz50);
            $alpha = $this->converter->toAlpha($color);

            $hslWithMissing = $this->toHslWithMissingChannels($color);
            $lightnessNode  = new NumberNode($lch->lValue(), '%');
            $hueNode        = new NumberNode($lch->hValue(), 'deg');

            if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
                $oklch = $this->extractOklchMixData($color);
                $lch   = $this->runtime->spaceConverter->oklchToLch(new OklchColor(
                    l: $oklch['l'],
                    c: $oklch['c'],
                    h: $oklch['h'],
                    a: $oklch['a'],
                ));

                $lightnessNode = $oklch['l_missing']
                    ? new StringNode('none')
                    : new NumberNode($lch->lValue(), '%');

                $hueNode = $oklch['h_missing'] || abs($lch->cValue()) < 0.0000001
                    ? new StringNode('none')
                    : new NumberNode($lch->hValue(), 'deg');

                return $this->converter->buildFunctionalColorNode('lch', [
                    $lightnessNode,
                    new NumberNode($lch->cValue()),
                    $hueNode,
                ], $oklch['a']);
            }

            if (($hslWithMissing !== null && $hslWithMissing->h === null) || abs($lch->cValue()) < 0.0000001) {
                $hueNode = new StringNode('none');
            }

            if ($this->isSemanticChannelMissing($color)) {
                $lightnessNode = $this->missingStringNode();
            }

            return $this->converter->buildFunctionalColorNode('lch', [
                $lightnessNode,
                new NumberNode($lch->cValue()),
                $hueNode,
            ], $alpha);
        }

        if ($space === 'oklch') {
            $xyzD65 = $color instanceof FunctionNode ? $this->converter->toXyzD65WithAlpha($color) : null;
            $oklch  = $xyzD65 === null
                ? $this->toOklchPreservingMissingChannels($color)
                : $this->runtime->spaceConverter->xyzD65ToOklch($xyzD65[0], $xyzD65[1]);

            $lightnessNode = new NumberNode($oklch->lValue(), '%');
            $hueNode       = new NumberNode($oklch->hValue(), 'deg');

            if ($this->isSemanticChannelMissing($color)) {
                $lightnessNode = $this->missingStringNode();
            }

            if (abs($oklch->cValue()) < 0.0000001) {
                $hueNode = new StringNode('none');
            }

            return $this->converter->buildFunctionalColorNode('oklch', [
                $lightnessNode,
                new NumberNode($oklch->cValue()),
                $hueNode,
            ], $oklch->a);
        }

        if ($space === 'lab') {
            return $this->converter->buildLabColorNode(
                $this->runtime->spaceConverter->xyzD50ToLabColor(
                    $this->converter->toXyzD50($color),
                    $this->converter->toAlpha($color),
                ),
            );
        }

        if ($space === 'oklab') {
            return $this->converter->buildOklabColorNode(
                $this->runtime->spaceConverter->xyzD65ToOklabColor(
                    $this->converter->toXyzD65($color),
                    $this->converter->toAlpha($color),
                ),
            );
        }

        if ($space === 'xyz-d50') {
            $xyz = $this->converter->toXyzD50($color);

            return $this->converter->buildGenericColorFunctionNode(
                'xyz-d50',
                [$xyz->x, $xyz->y, $xyz->z],
                $this->converter->toAlpha($color),
            );
        }

        if ($space === 'xyz' || $space === 'xyz-d65') {
            $xyz = $this->converter->toXyzD65($color);

            return $this->converter->buildGenericColorFunctionNode(
                $space,
                [$xyz->x, $xyz->y, $xyz->z],
                $this->converter->toAlpha($color),
            );
        }

        if ($space === 'srgb-linear') {
            return $this->toSrgbLinear($color);
        }

        if (in_array($space, ['display-p3-linear', 'display-p3', 'a98-rgb', 'rec2020'], true)) {
            return $this->toXyzD65GenericSpace($color, $space);
        }

        if ($space === 'prophoto-rgb') {
            return $this->toXyzD50GenericSpace($color, $space);
        }

        if (! in_array($space, ['rgb', 'srgb', 'hsl', 'hwb'], true)) {
            throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('to-space'));
        }

        $rgb = $this->converter->toRgb($color);

        if ($space === 'rgb') {
            return $this->converter->serializeRgbFromAstSource($color, $rgb);
        }

        if ($space === 'srgb') {
            return $this->converter->buildFunctionalColorNode('color', [
                new StringNode('srgb'),
                new NumberNode($rgb->rValue() / 255.0),
                new NumberNode($rgb->gValue() / 255.0),
                new NumberNode($rgb->bValue() / 255.0),
            ], $rgb->a);
        }

        $hsl = $this->converter->toHsl($color);

        return $this->converter->buildHslFunctionNode($hsl->hValue(), $hsl->sValue(), $hsl->lValue(), $hsl->a);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function toGamut(array $positional, array $named): AstNode
    {
        $color = $this->runtime->argumentParser->requireColor($positional, 0, 'to-gamut');

        $space = strtolower($this->runtime->argumentParser->asString(
            $named['space'] ?? ($positional[1] ?? new StringNode('rgb')),
            'to-gamut',
        ));

        $method = strtolower($this->runtime->argumentParser->asString(
            $named['method'] ?? ($positional[2] ?? new StringNode('local-minde')),
            'to-gamut',
        ));

        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (! in_array($method, ['local-minde', 'clip'], true)) {
            throw new UnsupportedColorValueException("Unknown gamut mapping method: $method");
        }

        if ($space === 'rgb' || $space === 'srgb') {
            $rgb = $this->converter->toUnclampedRgb($color);

            if ($nativeSpace === 'oklch') {
                return $this->toGamutFromOklch($color, $method);
            }

            $isInGamut = $rgb->r >= 0.0 && $rgb->r <= 255.0
                && $rgb->g >= 0.0 && $rgb->g <= 255.0
                && $rgb->b >= 0.0 && $rgb->b <= 255.0;

            if ($isInGamut) {
                return $color;
            }

            if ($method === 'clip') {
                return $this->serializeRgbForOriginalSpace($nativeSpace, new RgbColor(
                    r: $this->runtime->argumentParser->clamp($rgb->rValue(), 255.0),
                    g: $this->runtime->argumentParser->clamp($rgb->gValue(), 255.0),
                    b: $this->runtime->argumentParser->clamp($rgb->bValue(), 255.0),
                    a: $rgb->a,
                ));
            }

            $oklch    = $this->runtime->spaceConverter->rgbToOklch($rgb);
            $mapped   = $this->gamutMapper->localMinde($oklch);
            $finalRgb = $this->runtime->spaceConverter->oklchToSrgb($mapped);

            return $this->serializeRgbForOriginalSpace($nativeSpace, new RgbColor(
                r: $this->runtime->argumentParser->clamp($finalRgb->rValue() * 255.0, 255.0),
                g: $this->runtime->argumentParser->clamp($finalRgb->gValue() * 255.0, 255.0),
                b: $this->runtime->argumentParser->clamp($finalRgb->bValue() * 255.0, 255.0),
                a: $finalRgb->a,
            ));
        }

        throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('to-gamut'));
    }

    public function toGamutFromOklch(AstNode $color, string $method): AstNode
    {
        $oklch  = $this->extractOklchColor($color);
        $mapped = $method === 'clip'
            ? $this->gamutMapper->clip($oklch)
            : $this->gamutMapper->localMinde($oklch);

        return $this->converter->serializeAsOklchString($mapped);
    }

    public function serializeRgbForOriginalSpace(string $space, RgbColor $rgb): AstNode
    {
        if ($space === 'rgb' || $space === 'srgb') {
            return $this->converter->fromRgb($rgb);
        }

        if (in_array($space, ['oklch', 'oklab', 'lch', 'lab'], true)) {
            return $this->toSpace([
                $this->converter->fromRgb($rgb),
                new StringNode($space),
            ]);
        }

        return $this->converter->fromRgb($rgb);
    }

    public function toOklchPreservingMissingChannels(AstNode $color): OklchColor
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $channels  = $this->converter->extractChannelNodes($color);
            $lightness = $this->runtime->argumentParser->isMissingChannelNode(
                $channels[0] ?? new StringNode('none'),
            )
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
                $this->runtime->spaceConverter->lchChannelsToXyzD65($lightness, $chroma, $hue),
            );
        }

        return $this->runtime->spaceConverter->rgbToOklch($this->converter->toRgb($color));
    }

    public function extractOklchColor(AstNode $color): OklchColor
    {
        return $this->converter->extractOklch($color, 'to-gamut');
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function rgbToWorkingSpaceChannels(RgbColor $rgb, string $space): array
    {
        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = match ($space) {
            'display-p3'   => $this->runtime->spaceConverter->rgbToDisplayP3($rgb),
            'a98-rgb'      => $this->runtime->spaceConverter->rgbToA98Rgb($rgb),
            'prophoto-rgb' => $this->runtime->spaceConverter->rgbToProphotoRgb($rgb),
            'rec2020'      => $this->runtime->spaceConverter->rgbToRec2020($rgb),
            default        => throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('invert')),
        };

        return $channels;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $channels
     */
    public function workingSpaceChannelsToRgb(string $space, array $channels, float $alpha): RgbColor
    {
        try {
            $rgba = $this->runtime->spaceRouter->convertToRgba(
                $space,
                $channels[0],
                $channels[1],
                $channels[2],
                1.0,
            );
        } catch (UnsupportedColorSpace) {
            throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('invert'));
        }

        return new RgbColor(
            r: $rgba->rValue() * 255.0,
            g: $rgba->gValue() * 255.0,
            b: $rgba->bValue() * 255.0,
            a: $alpha,
        );
    }

    /**
     * @return array{l: float, c: float, h: float, a: float, l_missing: bool, c_missing: bool, h_missing: bool}
     */
    public function extractOklchMixData(AstNode $color): array
    {
        return $this->converter->extractOklchMixData($color, 'mix');
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

    private function toSrgbLinear(AstNode $color): AstNode
    {
        $rgb = $this->converter->toRgb($color);

        return $this->converter->buildGenericColorFunctionNode('srgb-linear', [
            $this->runtime->spaceConverter->srgbToLinearUnclamped($rgb->rValue() / 255.0),
            $this->runtime->spaceConverter->srgbToLinearUnclamped($rgb->gValue() / 255.0),
            $this->runtime->spaceConverter->srgbToLinearUnclamped($rgb->bValue() / 255.0),
        ], $rgb->a);
    }

    private function toXyzD65GenericSpace(AstNode $color, string $space): AstNode
    {
        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = match ($space) {
            'display-p3-linear' => $this->runtime->spaceConverter->xyzD65ToLinearDisplayP3($this->converter->toXyzD65($color)),
            'display-p3'        => $this->runtime->spaceConverter->xyzD65ToDisplayP3($this->converter->toXyzD65($color)),
            'a98-rgb'           => $this->runtime->spaceConverter->xyzD65ToA98Rgb($this->converter->toXyzD65($color)),
            default             => $this->runtime->spaceConverter->xyzD65ToRec2020($this->converter->toXyzD65($color)),
        };

        [$r, $g, $b] = $channels;

        return $this->converter->buildGenericColorFunctionNode($space, [$r, $g, $b], $this->converter->toAlpha($color));
    }

    private function toXyzD50GenericSpace(AstNode $color, string $space): AstNode
    {
        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = $this->runtime->spaceConverter->xyzD50ToProphotoRgb($this->converter->toXyzD50($color));

        [$r, $g, $b] = $channels;

        return $this->converter->buildGenericColorFunctionNode($space, [$r, $g, $b], $this->converter->toAlpha($color));
    }
}
