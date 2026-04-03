<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Exceptions\UnsupportedColorSpace;
use Bugo\Iris\Operations\GamutMapper;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstReader;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstWriter;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorChannelSchema;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Closure;

use function abs;
use function count;
use function in_array;
use function strtolower;

final readonly class ColorSpaceInterop
{
    /**
     * @param Closure(string): string $errorCtx
     */
    public function __construct(
        private ColorArgumentParser $parser,
        private ColorNodeConverter $converter,
        private ColorAstWriter $astWriter,
        private ColorAstReader $astReader,
        private HexColorConverter $hexColorConverter,
        private ColorConverterInterface $colorSpaceConverter,
        private ColorChannelSchema $channelSchema,
        private Closure $errorCtx,
        private GamutMapper $gamutMapper = new GamutMapper(),
    ) {}

    /**
     * @param array<int, AstNode> $positional
     */
    public function toSpace(array $positional): AstNode
    {
        $color       = $this->parser->requireColor($positional, 0, 'to-space');
        $space       = strtolower($this->parser->asString($positional[1] ?? null, 'to-space'));
        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (
            $color instanceof FunctionNode
            && ($nativeSpace === $space || ($nativeSpace === 'xyz' && $space === 'xyz-d65'))
        ) {
            return $color;
        }

        if ($space === 'lch') {
            $xyz50 = $this->converter->toXyzD50($color);
            $lch   = $this->colorSpaceConverter->xyzD50ToLch($xyz50);
            $alpha = $this->converter->toAlpha($color);

            $hslWithMissing = $this->toHslWithMissingChannels($color);
            $lightnessNode  = new NumberNode($lch->lValue(), '%');
            $hueNode        = new NumberNode($lch->hValue(), 'deg');

            if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
                $oklch = $this->extractOklchMixData($color);
                $lch   = $this->colorSpaceConverter->oklchToLch(new OklchColor(
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

                return $this->astWriter->buildFunctionalColorNode('lch', [
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

            return $this->astWriter->buildFunctionalColorNode('lch', [
                $lightnessNode,
                new NumberNode($lch->cValue()),
                $hueNode,
            ], $alpha);
        }

        if ($space === 'oklch') {
            $xyzD65 = $color instanceof FunctionNode ? $this->converter->toXyzD65WithAlpha($color) : null;
            $oklch  = $xyzD65 === null
                ? $this->toOklchPreservingMissingChannels($color)
                : $this->colorSpaceConverter->xyzD65ToOklch($xyzD65[0], $xyzD65[1]);

            $lightnessNode = new NumberNode($oklch->lValue(), '%');
            $hueNode       = new NumberNode($oklch->hValue(), 'deg');

            if ($this->isSemanticChannelMissing($color)) {
                $lightnessNode = $this->missingStringNode();
            }

            if (abs($oklch->cValue()) < 0.0000001) {
                $hueNode = new StringNode('none');
            }

            return $this->astWriter->buildFunctionalColorNode('oklch', [
                $lightnessNode,
                new NumberNode($oklch->cValue()),
                $hueNode,
            ], $oklch->a);
        }

        if ($space === 'lab') {
            return $this->astWriter->buildLabColorNode(
                $this->colorSpaceConverter->xyzD50ToLabColor(
                    $this->converter->toXyzD50($color),
                    $this->converter->toAlpha($color),
                ),
            );
        }

        if ($space === 'oklab') {
            return $this->astWriter->buildOklabColorNode(
                $this->colorSpaceConverter->xyzD65ToOklabColor(
                    $this->converter->toXyzD65($color),
                    $this->converter->toAlpha($color),
                ),
            );
        }

        if ($space === 'xyz-d50') {
            $xyz = $this->converter->toXyzD50($color);

            return $this->astWriter->buildGenericColorFunctionNode(
                'xyz-d50',
                [$xyz->x, $xyz->y, $xyz->z],
                $this->converter->toAlpha($color),
            );
        }

        if ($space === 'xyz' || $space === 'xyz-d65') {
            $xyz = $this->converter->toXyzD65($color);

            return $this->astWriter->buildGenericColorFunctionNode(
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
            throw new UnsupportedColorSpaceException($space, ($this->errorCtx)('to-space'));
        }

        $rgb = $this->converter->toRgb($color);

        if ($space === 'rgb') {
            return $this->astWriter->serializeRgbFromAstSource($color, $rgb);
        }

        if ($space === 'srgb') {
            return $this->astWriter->buildFunctionalColorNode('color', [
                new StringNode('srgb'),
                new NumberNode($rgb->rValue() / 255.0),
                new NumberNode($rgb->gValue() / 255.0),
                new NumberNode($rgb->bValue() / 255.0),
            ], $rgb->a);
        }

        $hsl = $this->converter->toHsl($color);

        return $this->astWriter->buildHslFunctionNode($hsl->hValue(), $hsl->sValue(), $hsl->lValue(), $hsl->a);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function toGamut(array $positional, array $named): AstNode
    {
        $color = $this->parser->requireColor($positional, 0, 'to-gamut');

        $space = strtolower($this->parser->asString(
            $named['space'] ?? ($positional[1] ?? new StringNode('rgb')),
            'to-gamut',
        ));

        $method = strtolower($this->parser->asString(
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
                    r: $this->parser->clamp($rgb->rValue(), 255.0),
                    g: $this->parser->clamp($rgb->gValue(), 255.0),
                    b: $this->parser->clamp($rgb->bValue(), 255.0),
                    a: $rgb->a,
                ));
            }

            $oklch    = $this->colorSpaceConverter->rgbToOklch($rgb);
            $mapped   = $this->gamutMapper->localMinde($oklch);
            $finalRgb = $this->colorSpaceConverter->oklchToSrgb($mapped);

            return $this->serializeRgbForOriginalSpace($nativeSpace, new RgbColor(
                r: $this->parser->clamp($finalRgb->rValue() * 255.0, 255.0),
                g: $this->parser->clamp($finalRgb->gValue() * 255.0, 255.0),
                b: $this->parser->clamp($finalRgb->bValue() * 255.0, 255.0),
                a: $finalRgb->a,
            ));
        }

        throw new UnsupportedColorSpaceException($space, ($this->errorCtx)('to-gamut'));
    }

    public function toGamutFromOklch(AstNode $color, string $method): AstNode
    {
        $oklch  = $this->extractOklchColor($color);
        $mapped = $method === 'clip'
            ? $this->gamutMapper->clip($oklch)
            : $this->gamutMapper->localMinde($oklch);

        return $this->astWriter->serializeAsOklchString($mapped);
    }

    public function serializeRgbForOriginalSpace(string $space, RgbColor $rgb): AstNode
    {
        if ($space === 'rgb' || $space === 'srgb') {
            return $this->astWriter->fromRgb($rgb);
        }

        if (in_array($space, ['oklch', 'oklab', 'lch', 'lab'], true)) {
            return $this->toSpace([
                $this->astWriter->fromRgb($rgb),
                new StringNode($space),
            ]);
        }

        return $this->astWriter->fromRgb($rgb);
    }

    public function toOklchPreservingMissingChannels(AstNode $color): OklchColor
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'lch') {
            $channels  = $this->converter->extractChannelNodes($color);
            $lightness = $this->parser->isMissingChannelNode($channels[0] ?? new StringNode('none'))
                ? 0.0
                : $this->parser->clamp(
                    $this->parser->asPercentage($channels[0] ?? null, 'to-space'),
                    100.0,
                );

            $chroma = $this->parser->asAbsoluteChannel($channels[1] ?? null, 'to-space', 150.0);
            $hue    = $this->parser->normalizeHue(
                $this->parser->asHueAngle($channels[2] ?? null, 'to-space'),
            );

            return $this->colorSpaceConverter->xyzD65ToOklch(
                $this->colorSpaceConverter->lchChannelsToXyzD65($lightness, $chroma, $hue),
            );
        }

        return $this->colorSpaceConverter->rgbToOklch($this->converter->toRgb($color));
    }

    public function extractOklchColor(AstNode $color): OklchColor
    {
        return $this->astReader->extractOklch($color, 'to-gamut');
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function rgbToWorkingSpaceChannels(RgbColor $rgb, string $space): array
    {
        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = match ($space) {
            'display-p3'   => $this->colorSpaceConverter->rgbToDisplayP3($rgb),
            'a98-rgb'      => $this->colorSpaceConverter->rgbToA98Rgb($rgb),
            'prophoto-rgb' => $this->colorSpaceConverter->rgbToProphotoRgb($rgb),
            'rec2020'      => $this->colorSpaceConverter->rgbToRec2020($rgb),
            default        => throw new UnsupportedColorSpaceException($space, ($this->errorCtx)('invert')),
        };

        return $channels;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $channels
     */
    public function workingSpaceChannelsToRgb(string $space, array $channels, float $alpha): RgbColor
    {
        try {
            $rgba = $this->colorSpaceConverter->convertToRgba(
                $space,
                $channels[0],
                $channels[1],
                $channels[2],
                1.0,
            );
        } catch (UnsupportedColorSpace) {
            throw new UnsupportedColorSpaceException($space, ($this->errorCtx)('invert'));
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
        return $this->astReader->extractOklchMixData($color, 'mix');
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
        $expandedArgs = $this->parser->expandSingleSpaceListArgument($color->arguments);

        [$channels, $alpha] = $this->hexColorConverter->splitChannelsAndAlpha($expandedArgs);

        if (count($channels) !== 3) {
            return null;
        }

        $hNone = $this->parser->isMissingChannelNode($channels[0]);
        $sNone = $this->parser->isMissingChannelNode($channels[1]);
        $lNone = $this->parser->isMissingChannelNode($channels[2]);

        $hue = $hNone ? null : $this->parser->normalizeHue($this->parser->asNumber($channels[0], 'mix'));

        $sat = $sNone ? null : $this->parser->clamp(
            $this->parser->asPercentage($channels[1], 'mix'),
            100.0,
        );

        $lig = $lNone ? null : $this->parser->clamp(
            $this->parser->asPercentage($channels[2], 'mix'),
            100.0,
        );

        $alp = 1.0;

        if ($alpha !== null) {
            $alp = $this->parser->isMissingChannelNode($alpha) ? 0.0 : $this->parser->clamp(
                $this->parser->asNumber($alpha, 'mix'),
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

        $expandedArgs = $this->parser->expandSingleSpaceListArgument($color->arguments);
        $channelIndex = $this->channelSchema->lightnessIndexForFunction(strtolower($color->name));

        if ($channelIndex === null || ! isset($expandedArgs[$channelIndex])) {
            return false;
        }

        return $this->parser->isMissingChannelNode($expandedArgs[$channelIndex]);
    }

    public function missingStringNode(): StringNode
    {
        return new StringNode('none');
    }

    private function toSrgbLinear(AstNode $color): AstNode
    {
        $rgb = $this->converter->toRgb($color);

        return $this->astWriter->buildGenericColorFunctionNode('srgb-linear', [
            $this->colorSpaceConverter->srgbToLinearUnclamped($rgb->rValue() / 255.0),
            $this->colorSpaceConverter->srgbToLinearUnclamped($rgb->gValue() / 255.0),
            $this->colorSpaceConverter->srgbToLinearUnclamped($rgb->bValue() / 255.0),
        ], $rgb->a);
    }

    private function toXyzD65GenericSpace(AstNode $color, string $space): AstNode
    {
        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = match ($space) {
            'display-p3-linear' => $this->colorSpaceConverter->xyzD65ToLinearDisplayP3($this->converter->toXyzD65($color)),
            'display-p3'        => $this->colorSpaceConverter->xyzD65ToDisplayP3($this->converter->toXyzD65($color)),
            'a98-rgb'           => $this->colorSpaceConverter->xyzD65ToA98Rgb($this->converter->toXyzD65($color)),
            default             => $this->colorSpaceConverter->xyzD65ToRec2020($this->converter->toXyzD65($color)),
        };

        [$r, $g, $b] = $channels;

        return $this->astWriter->buildGenericColorFunctionNode($space, [$r, $g, $b], $this->converter->toAlpha($color));
    }

    private function toXyzD50GenericSpace(AstNode $color, string $space): AstNode
    {
        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = $this->colorSpaceConverter->xyzD50ToProphotoRgb($this->converter->toXyzD50($color));

        [$r, $g, $b] = $channels;

        return $this->astWriter->buildGenericColorFunctionNode($space, [$r, $g, $b], $this->converter->toAlpha($color));
    }
}
