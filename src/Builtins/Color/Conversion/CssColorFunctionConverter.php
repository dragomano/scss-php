<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisConverterAdapter;
use Bugo\SCSS\Builtins\Color\Support\ColorChannelSplitterTrait;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\AstValueInspector;

use function abs;
use function count;
use function round;
use function strtolower;

use const M_PI;

final readonly class CssColorFunctionConverter
{
    use ColorChannelSplitterTrait;

    public function __construct(
        private ColorConverterInterface $colorSpaceConverter = new IrisConverterAdapter(),
    ) {}

    public function tryConvertToRgba(FunctionNode $function): ?RgbColor
    {
        $name = strtolower($function->name);

        return match ($name) {
            'oklab', 'lab',
            'oklch', 'lch' => $this->parseCssPerceptualFunction($function),
            'rgb', 'rgba'  => $this->parseCssRgbFunction($function),
            'hsl', 'hsla'  => $this->parseCssHslFunction($function),
            'hwb'          => $this->parseCssHwbFunction($function),
            'color'        => $this->parseCssColorFunction($function),
            default        => null,
        };
    }

    /**
     * @return array{0: XyzColor, 1: float}|null
     */
    public function tryConvertToXyzD65(FunctionNode $function): ?array
    {
        $name = strtolower($function->name);

        if ($name === 'color') {
            return $this->parseCssColorFunctionToXyzD65($function);
        }

        if ($name === 'lab' || $name === 'lch') {
            $xyz = $this->tryConvertToXyzD50($function);

            if ($xyz === null) {
                return null;
            }

            [$x, $y, $z] = $this->colorSpaceConverter->xyzD50ToD65($xyz[0]->x, $xyz[0]->y, $xyz[0]->z);

            return [new XyzColor($x, $y, $z), $xyz[1]];
        }

        if ($name === 'oklab' || $name === 'oklch') {
            return $this->parseOklabLikeToXyzD65($function);
        }

        $rgba = $this->tryConvertToRgba($function);

        if ($rgba === null) {
            return null;
        }

        return [$this->convertSrgbRgbaToXyzD65($rgba), $rgba->a];
    }

    /**
     * @return array{0: XyzColor, 1: float}|null
     */
    public function tryConvertToXyzD50(FunctionNode $function): ?array
    {
        $name = strtolower($function->name);

        if ($name === 'lab' || $name === 'lch') {
            return $this->parseLabLikeToXyzD50($function);
        }

        $xyz = $this->tryConvertToXyzD65($function);

        if ($xyz === null) {
            return null;
        }

        [$x, $y, $z] = $this->colorSpaceConverter->xyzD65ToD50($xyz[0]->x, $xyz[0]->y, $xyz[0]->z);

        return [new XyzColor($x, $y, $z), $xyz[1]];
    }

    private function parseCssRgbFunction(FunctionNode $function): ?RgbColor
    {
        $parsed = $this->parseFunctionThreeChannels(
            $function,
            $this->parseRgbChannelValue(...),
            $this->parseRgbChannelValue(...),
            $this->parseRgbChannelValue(...),
        );

        if ($parsed === null) {
            return null;
        }

        return new RgbColor($parsed[0], $parsed[1], $parsed[2], $parsed[3]);
    }

    private function parseCssHslFunction(FunctionNode $function): ?RgbColor
    {
        return $this->withParsedThreeChannels(
            $function,
            $this->parseHueDegrees(...),
            $this->parsePercentageFraction(...),
            $this->parsePercentageFraction(...),
            function (float $hue, float $saturation, float $lightness, float $opacity): RgbColor {
                [$r, $g, $b] = $this->colorSpaceConverter->hslToRgb($hue, $saturation, $lightness);

                return new RgbColor($r, $g, $b, $opacity);
            },
        );
    }

    private function parseCssHwbFunction(FunctionNode $function): ?RgbColor
    {
        return $this->withParsedThreeChannels(
            $function,
            $this->parseHueDegrees(...),
            $this->parsePercentageFraction(...),
            $this->parsePercentageFraction(...),
            function (float $hue, float $whiteness, float $blackness, float $opacity): RgbColor {
                [$r, $g, $b] = $this->colorSpaceConverter->hwbToRgb($hue, $whiteness, $blackness);

                return new RgbColor($r, $g, $b, $opacity);
            },
        );
    }

    private function parseCssPerceptualFunction(FunctionNode $function): ?RgbColor
    {
        return match (strtolower($function->name)) {
            'lab'   => $this->parseCssLabLike(
                $function,
                fn(float $l, float $a, float $b, float $opacity): RgbColor
                    => $this->colorSpaceConverter->convertToRgba('lab', $l, $a, $b, $opacity),
                125.0,
            ),
            'lch'   => $this->parseCssLchLike(
                $function,
                fn(float $l, float $c, float $h, float $opacity): RgbColor
                    => $this->colorSpaceConverter->convertToRgba('lch', $l, $c, $h, $opacity),
                150.0,
            ),
            'oklab' => $this->parseCssLabLike(
                $function,
                fn(float $l, float $a, float $b, float $opacity): RgbColor
                    => $this->colorSpaceConverter->convertToRgba('oklab', $l / 100.0, $a, $b, $opacity),
                0.4,
            ),
            'oklch' => $this->parseCssLchLike(
                $function,
                fn(float $l, float $c, float $h, float $opacity): RgbColor
                    => $this->colorSpaceConverter->convertToRgba('oklch', $l / 100.0, $c, $h, $opacity),
                0.4,
            ),
            default => null,
        };
    }

    private function parseCssColorFunction(FunctionNode $function): ?RgbColor
    {
        $parsed = $this->parseGenericColorFunction($function);

        if ($parsed === null) {
            return null;
        }

        [$space, $c1, $c2, $c3, $opacity] = $parsed;

        if (! $this->isSupportedColorFunctionSpace($space)) {
            return null;
        }

        $rgba = $this->colorSpaceConverter->convertToRgba($space, $c1, $c2, $c3, $opacity);

        return $space === 'xyz-d50'
            ? $this->snapNearShortHexRgbChannels($rgba)
            : $rgba;
    }

    /**
     * @return array{0: XyzColor, 1: float}|null
     */
    private function parseCssColorFunctionToXyzD65(FunctionNode $function): ?array
    {
        $parsed = $this->parseGenericColorFunction($function);

        if ($parsed === null) {
            return null;
        }

        [$space, $c1, $c2, $c3, $opacity] = $parsed;

        if (! $this->isSupportedColorFunctionSpace($space)) {
            return null;
        }

        $xyz = $this->colorSpaceConverter->convertToXyzD65($space, $c1, $c2, $c3);

        return [$xyz, $opacity];
    }

    private function convertSrgbRgbaToXyzD65(RgbColor $rgba): XyzColor
    {
        return $this->colorSpaceConverter->convertToXyzD65(
            'srgb',
            $rgba->rValue(),
            $rgba->gValue(),
            $rgba->bValue(),
        );
    }

    /**
     * @param callable(float, float, float, float): RgbColor $converter
     */
    private function parseCssLabLike(FunctionNode $function, callable $converter, float $channelPercentMax): ?RgbColor
    {
        $parsed = $this->parseFunctionThreeChannels(
            $function,
            $this->parseLabLightness(...),
            fn(AstNode $node): ?float => $this->parseAbsoluteChannel($node, $channelPercentMax),
            fn(AstNode $node): ?float => $this->parseAbsoluteChannel($node, $channelPercentMax),
        );

        if ($parsed === null) {
            return null;
        }

        [$lightness, $a, $b, $opacity] = $parsed;

        return $converter($lightness, $a, $b, $opacity);
    }

    /**
     * @return array{0: XyzColor, 1: float}|null
     */
    private function parseLabLikeToXyzD50(FunctionNode $function): ?array
    {
        $name = strtolower($function->name);

        $parsed = $this->parseFunctionThreeChannels(
            $function,
            $this->parseLabLightness(...),
            fn(AstNode $node): ?float => $this->parseAbsoluteChannel($node, $name === 'lab' ? 125.0 : 150.0),
            $name === 'lab'
                ? fn(AstNode $node): ?float => $this->parseAbsoluteChannel($node, 125.0)
                : $this->parseHueDegrees(...),
        );

        if ($parsed === null) {
            return null;
        }

        [$lightness, $channel2, $channel3, $opacity] = $parsed;

        if ($name === 'lab') {
            return [$this->colorSpaceConverter->labToXyzD50($lightness, $channel2, $channel3), $opacity];
        }

        $xyzD65 = $this->colorSpaceConverter->lchChannelsToXyzD65($lightness, $channel2, $channel3);

        [$x, $y, $z] = $this->colorSpaceConverter->xyzD65ToD50($xyzD65->x, $xyzD65->y, $xyzD65->z);

        return [new XyzColor($x, $y, $z), $opacity];
    }

    /**
     * @param callable(float, float, float, float): RgbColor $converter
     */
    private function parseCssLchLike(FunctionNode $function, callable $converter, float $chromaPercentMax): ?RgbColor
    {
        return $this->withParsedThreeChannels(
            $function,
            $this->parseLabLightness(...),
            fn(AstNode $node): ?float => $this->parseAbsoluteChannel($node, $chromaPercentMax),
            $this->parseHueDegrees(...),
            fn(float $lightness, float $chroma, float $hue, float $opacity): RgbColor
                => $converter($lightness, $chroma, $hue, $opacity),
        );
    }

    /**
     * @return array{0: XyzColor, 1: float}|null
     */
    private function parseOklabLikeToXyzD65(FunctionNode $function): ?array
    {
        $name = strtolower($function->name);

        $parsed = $this->parseFunctionThreeChannels(
            $function,
            $this->parseLabLightness(...),
            fn(AstNode $node): ?float => $this->parseAbsoluteChannel($node, 0.4),
            $name === 'oklab'
                ? fn(AstNode $node): ?float => $this->parseAbsoluteChannel($node, 0.4)
                : $this->parseHueDegrees(...),
        );

        if ($parsed === null) {
            return null;
        }

        [$lightness, $channel2, $channel3, $opacity] = $parsed;

        return [
            $name === 'oklab'
                ? $this->colorSpaceConverter->oklabChannelsToXyzD65($lightness / 100.0, $channel2, $channel3)
                : $this->colorSpaceConverter->oklchChannelsToXyzD65($lightness / 100.0, $channel2, $channel3),
            $opacity,
        ];
    }

    /**
     * @return array{0: array<int, AstNode>, 1: ?AstNode}|null
     */
    private function extractThreeChannels(FunctionNode $function): ?array
    {
        [$channels, $alpha] = $this->splitChannelsAndAlpha(
            $this->expandArguments($function),
        );

        if (count($channels) !== 3) {
            return null;
        }

        return [$channels, $alpha];
    }

    /**
     * @param callable(AstNode): ?float $parser1
     * @param callable(AstNode): ?float $parser2
     * @param callable(AstNode): ?float $parser3
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function parseFunctionThreeChannels(
        FunctionNode $function,
        callable $parser1,
        callable $parser2,
        callable $parser3,
    ): ?array {
        $result = $this->extractThreeChannels($function);

        if ($result === null) {
            return null;
        }

        [$channels, $alpha] = $result;

        return $this->parseThreeChannelsWithAlpha($channels, $alpha, $parser1, $parser2, $parser3);
    }

    /**
     * @param callable(AstNode): ?float $parser1
     * @param callable(AstNode): ?float $parser2
     * @param callable(AstNode): ?float $parser3
     * @param callable(float, float, float, float): ?RgbColor $callback
     */
    private function withParsedThreeChannels(
        FunctionNode $function,
        callable $parser1,
        callable $parser2,
        callable $parser3,
        callable $callback,
    ): ?RgbColor {
        $parsed = $this->parseFunctionThreeChannels($function, $parser1, $parser2, $parser3);

        if ($parsed === null) {
            return null;
        }

        return $callback($parsed[0], $parsed[1], $parsed[2], $parsed[3]);
    }

    /**
     * @return array{0: string, 1: float, 2: float, 3: float, 4: float}|null
     */
    private function parseGenericColorFunction(FunctionNode $function): ?array
    {
        [$channelsWithSpace, $alpha] = $this->splitChannelsAndAlpha(
            $this->expandArguments($function),
            false,
        );

        if (count($channelsWithSpace) !== 4 || ! ($channelsWithSpace[0] instanceof StringNode)) {
            return null;
        }

        $space   = strtolower($channelsWithSpace[0]->value);
        $c1      = $this->parseColorFunctionChannel($channelsWithSpace[1]);
        $c2      = $this->parseColorFunctionChannel($channelsWithSpace[2]);
        $c3      = $this->parseColorFunctionChannel($channelsWithSpace[3]);
        $opacity = $this->parseCssAlphaValue($alpha);

        if ($c1 === null || $c2 === null || $c3 === null || $opacity === null) {
            return null;
        }

        return [$space, $c1, $c2, $c3, $opacity];
    }

    /**
     * @param array<int, AstNode> $channels
     * @param callable(AstNode): ?float $parser1
     * @param callable(AstNode): ?float $parser2
     * @param callable(AstNode): ?float $parser3
     * @return array{0: float, 1: float, 2: float, 3: float}|null
     */
    private function parseThreeChannelsWithAlpha(
        array $channels,
        ?AstNode $alpha,
        callable $parser1,
        callable $parser2,
        callable $parser3,
    ): ?array {
        $value1  = $parser1($channels[0]);
        $value2  = $parser2($channels[1]);
        $value3  = $parser3($channels[2]);
        $opacity = $this->parseCssAlphaValue($alpha);

        if ($value1 === null || $value2 === null || $value3 === null || $opacity === null) {
            return null;
        }

        return [$value1, $value2, $value3, $opacity];
    }

    /**
     * @param callable(float, string): ?float $transform
     */
    private function parseChannelValue(AstNode $node, callable $transform): ?float
    {
        if ($this->isMissingChannelToken($node)) {
            return 0.0;
        }

        if (! ($node instanceof NumberNode)) {
            return null;
        }

        return $transform((float) $node->value, $node->unit ?? '');
    }

    private function applyPercentFraction(float $value, string $unit): ?float
    {
        if ($unit === '%') {
            $value /= 100.0;
        } elseif ($unit !== '') {
            return null;
        }

        return $this->clampFloat($value, 1.0);
    }

    private function parseAbsoluteChannel(AstNode $node, float $percentMax): ?float
    {
        return $this->parseChannelValue($node, function (float $value, string $unit) use ($percentMax): ?float {
            if ($unit === '%') {
                return $value * $percentMax / 100.0;
            }

            if ($unit !== '') {
                return null;
            }

            return $value;
        });
    }

    /**
     * @return array<int, AstNode>
     */
    private function expandArguments(FunctionNode $function): array
    {
        if (
            count($function->arguments) === 1
            && $function->arguments[0] instanceof ListNode
            && $function->arguments[0]->separator === 'space'
        ) {
            return $function->arguments[0]->items;
        }

        return $function->arguments;
    }

    private function parseRgbChannelValue(AstNode $node): ?float
    {
        return $this->parseChannelValue($node, function (float $value, string $unit): ?float {
            if ($unit === '%') {
                $value = $value * 255.0 / 100.0;
            } elseif ($unit !== '') {
                return null;
            }

            return $this->clampFloat($value / 255.0, 1.0);
        });
    }

    private function parseColorFunctionChannel(AstNode $node): ?float
    {
        return $this->parseChannelValue($node, function (float $value, string $unit): ?float {
            if ($unit === '%') {
                $value /= 100.0;
            } elseif ($unit !== '') {
                return null;
            }

            return $value;
        });
    }

    private function parseCssAlphaValue(?AstNode $node): ?float
    {
        if ($node === null) {
            return 1.0;
        }

        return $this->parseChannelValue($node, $this->applyPercentFraction(...));
    }

    private function parseHueDegrees(AstNode $node): ?float
    {
        return $this->parseChannelValue($node, function (float $value, string $unit): ?float {
            if ($unit === '' || $unit === 'deg') {
                return $this->normalizeHueDegrees($value);
            }

            if ($unit === 'rad') {
                return $this->normalizeHueDegrees($value * 180.0 / M_PI);
            }

            if ($unit === 'grad') {
                return $this->normalizeHueDegrees($value * 0.9);
            }

            if ($unit === 'turn') {
                return $this->normalizeHueDegrees($value * 360.0);
            }

            return null;
        });
    }

    private function normalizeHueDegrees(float $degrees): float
    {
        return $this->colorSpaceConverter->normalizeHue($degrees);
    }

    private function parsePercentageFraction(AstNode $node): ?float
    {
        return $this->parseChannelValue($node, $this->applyPercentFraction(...));
    }

    private function parseLabLightness(AstNode $node): ?float
    {
        return $this->parseChannelValue($node, function (float $value, string $unit): ?float {
            if ($unit !== '' && $unit !== '%') {
                return null;
            }

            return $this->clampFloat($value, 100.0);
        });
    }

    private function isSupportedColorFunctionSpace(string $space): bool
    {
        return in_array($space, [
            'srgb',
            'srgb-linear',
            'display-p3',
            'display-p3-linear',
            'a98-rgb',
            'prophoto-rgb',
            'rec2020',
            'xyz',
            'xyz-d65',
            'xyz-d50',
            'lab',
            'lch',
            'oklab',
            'oklch',
        ], true);
    }

    private function snapNearShortHexRgbChannels(RgbColor $rgba): RgbColor
    {
        if ((int) round($this->clampFloat($rgba->a, 1.0) * 255.0) < 255) {
            return $rgba;
        }

        $r = $rgba->rValue();
        $g = $rgba->gValue();
        $b = $rgba->bValue();

        $rByte = (int) round($this->clampFloat($r, 1.0) * 255.0);
        $gByte = (int) round($this->clampFloat($g, 1.0) * 255.0);
        $bByte = (int) round($this->clampFloat($b, 1.0) * 255.0);

        $rNibble = (int) round($rByte / 17);
        $gNibble = (int) round($gByte / 17);
        $bNibble = (int) round($bByte / 17);

        if (
            abs($rByte - $rNibble * 17) > 1
            || abs($gByte - $gNibble * 17) > 1
            || abs($bByte - $bNibble * 17) > 1
        ) {
            return $rgba;
        }

        return new RgbColor(
            r: (float) ($rNibble * 17) / 255.0,
            g: (float) ($gNibble * 17) / 255.0,
            b: (float) ($bNibble * 17) / 255.0,
            a: $rgba->a,
        );
    }

    private function clampFloat(float $value, float $max): float
    {
        return $this->colorSpaceConverter->clamp($value, $max);
    }

    private function isMissingChannelToken(AstNode $node): bool
    {
        return AstValueInspector::isNoneKeyword($node);
    }
}
