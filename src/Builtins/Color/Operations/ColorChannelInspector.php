<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\UnknownColorChannelException;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

use function abs;
use function strtolower;

final readonly class ColorChannelInspector
{
    public function __construct(private ColorRuntime $runtime, private ColorNodeConverter $converter) {}

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function channel(array $positional, array $named): NumberNode
    {
        $color       = $this->runtime->argumentParser->requireColor($positional, 0, 'channel');
        $channelName = $this->runtime->argumentParser->asString($positional[1] ?? null, 'channel');

        if (isset($named['space'])) {
            $space = strtolower($this->runtime->argumentParser->asString($named['space'], 'channel'));
        } elseif (isset($positional[2])) {
            $space = strtolower($this->runtime->argumentParser->asString($positional[2], 'channel'));
        } else {
            $space = $this->converter->detectNativeColorSpace($color);
        }

        return match ($space) {
            'rgb',
            'srgb',
            'hsl',
            'hwb',
            'lch',
            'lab',
            'oklch',
            'oklab',
            'xyz',
            'xyz-d50',
            'xyz-d65' => $this->resolveChannelValue($color, $space, $channelName),
            default   => throw new UnsupportedColorSpaceException($space, $this->runtime->context->errorCtx('channel')),
        };
    }

    public function resolveChannelValue(AstNode $color, string $space, string $channelName): NumberNode
    {
        return match ($space) {
            'rgb'            => $this->resolveRgbChannel($color, $channelName, false),
            'srgb'           => $this->resolveRgbChannel($color, $channelName, true),
            'hsl'            => $this->resolveHslChannel($color, $channelName),
            'hwb'            => $this->resolveHwbChannel($color, $channelName),
            'lch'            => $this->resolveLchChannel($color, $channelName),
            'lab'            => $this->resolveLabChannel($color, $channelName),
            'oklch'          => $this->resolveOklchChannel($color, $channelName),
            'oklab'          => $this->resolveOklabChannel($color, $channelName),
            'xyz', 'xyz-d65' => $this->resolveXyzD65Channel($color, $channelName),
            default          => $this->resolveXyzD50Channel($color, $channelName),
        };
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function deprecatedChannelValue(
        array $positional,
        string $space,
        string $channelName,
        string $context,
        ?BuiltinCallContext $callContext,
    ): NumberNode {
        $color = $this->runtime->argumentParser->requireColor($positional, 0, $context);

        $this->runtime->context->warn(
            $callContext,
            'color.channel(' . $this->runtime->formatter->describeValue($color) . ', "' . $channelName . '", $space: ' . $space . ')',
        );

        return $this->resolveChannelValue($color, $space, $channelName);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function channelAlpha(array $positional, string $name, ?BuiltinCallContext $context): AstNode
    {
        $isGlobal = $this->runtime->context->isGlobalBuiltinCall();

        try {
            $color = $isGlobal
                ? $this->runtime->argumentParser->requireColorOrDefer($positional, $name)
                : $this->runtime->argumentParser->requireColor($positional, 0, $name);
            $alpha = $this->converter->toRgb($color)->a;
        } catch (UnsupportedColorValueException $unsupportedColorValueException) {
            if ($isGlobal) {
                throw new DeferToCssFunctionException(
                    $unsupportedColorValueException->getMessage(),
                    0,
                    $unsupportedColorValueException,
                );
            }

            throw $unsupportedColorValueException;
        }

        if ($isGlobal) {
            $this->runtime->context->warn(
                $context,
                'color.channel(' . $this->runtime->formatter->describeValue($color) . ', "alpha")',
            );
        }

        return new NumberNode($alpha);
    }

    public function channelIndexForFunction(string $functionName, string $channelName): ?int
    {
        return $this->runtime->channelSchema->indexForChannel($functionName, $channelName);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function isMissing(array $positional): BooleanNode
    {
        $color       = $this->runtime->argumentParser->requireColor($positional, 0, 'is-missing');
        $channelName = strtolower($this->runtime->argumentParser->asString($positional[1] ?? null, 'is-missing'));

        if ($color instanceof StringNode) {
            $parsed = $this->converter->parseColorString($color->value);

            if ($parsed instanceof FunctionNode) {
                $color = $parsed;
            }
        }

        if (! ($color instanceof FunctionNode)) {
            return new BooleanNode(false);
        }

        $expandedArgs = $this->runtime->argumentParser->expandSingleSpaceListArgument($color->arguments);
        $channelIndex = $this->channelIndexForFunction(strtolower($color->name), $channelName);

        if ($channelIndex === null || ! isset($expandedArgs[$channelIndex])) {
            return new BooleanNode(false);
        }

        return new BooleanNode($this->runtime->argumentParser->isMissingChannelNode($expandedArgs[$channelIndex]));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function isPowerless(array $positional, array $named): BooleanNode
    {
        $color       = $this->runtime->argumentParser->requireColor($positional, 0, 'is-powerless');
        $channelName = $this->runtime->argumentParser->asString($positional[1] ?? null, 'is-powerless');

        $space = strtolower(
            $this->runtime->argumentParser->asString(
                $named['space'] ?? ($positional[2] ?? new StringNode('hsl')),
                'is-powerless',
            ),
        );

        $powerless = false;

        if ($space === 'hsl') {
            $hsl = $this->converter->toHsl($color);

            if ($channelName === 'hue') {
                $powerless = abs($hsl->sValue()) < 0.000001;
            } elseif ($channelName === 'saturation') {
                $powerless = $hsl->lValue() <= 0.0 || $hsl->lValue() >= 100.0;
            }
        }

        if ($space === 'hwb') {
            $hwb = $this->converter->toHwb($color);

            if ($channelName === 'hue') {
                $powerless = ($hwb->wValue() + $hwb->bValue()) >= 100.0;
            }
        }

        if ($space === 'lch') {
            if ($channelName === 'hue') {
                $lch       = $this->runtime->spaceConverter->rgbToLch($this->converter->toRgb($color));
                $powerless = abs($lch->cValue()) < 0.000001;
            }
        }

        if ($space === 'oklch') {
            if ($channelName === 'hue') {
                $oklch     = $this->runtime->spaceConverter->rgbToOklch($this->converter->toRgb($color));
                $powerless = abs($oklch->cValue()) < 0.000001;
            }
        }

        return new BooleanNode($powerless);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function space(array $positional): StringNode
    {
        $color = $this->runtime->argumentParser->requireColor($positional, 0, 'space');

        return new StringNode($this->converter->detectNativeColorSpace($color));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function isInGamut(array $positional): BooleanNode
    {
        $color = $this->runtime->argumentParser->requireColor($positional, 0, 'is-in-gamut');

        return new BooleanNode($this->converter->isInGamut($color));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function isLegacy(array $positional): BooleanNode
    {
        $color = $this->runtime->argumentParser->requireColor($positional, 0, 'is-legacy');

        return new BooleanNode($this->converter->isLegacyColor($color));
    }

    private function resolveRgbChannel(AstNode $color, string $channelName, bool $normalized): NumberNode
    {
        $rgb    = $this->converter->toRgb($color);
        $factor = $normalized ? 255.0 : 1.0;
        $label  = $normalized ? 'sRGB' : 'RGB';

        return match ($channelName) {
            'red'   => new NumberNode($rgb->rValue() / $factor),
            'green' => new NumberNode($rgb->gValue() / $factor),
            'blue'  => new NumberNode($rgb->bValue() / $factor),
            'alpha' => new NumberNode($rgb->a),
            default => throw new UnknownColorChannelException($label, $channelName),
        };
    }

    private function resolveHslChannel(AstNode $color, string $channelName): NumberNode
    {
        $hsl = $this->converter->toHsl($color);

        return match ($channelName) {
            'hue'        => new NumberNode($hsl->hValue(), 'deg'),
            'saturation' => new NumberNode($hsl->sValue(), '%'),
            'lightness'  => new NumberNode($hsl->lValue(), '%'),
            'alpha'      => new NumberNode($hsl->a),
            default      => throw new UnknownColorChannelException('HSL', $channelName),
        };
    }

    private function resolveHwbChannel(AstNode $color, string $channelName): NumberNode
    {
        $hwb = $this->converter->toHwb($color);

        return match ($channelName) {
            'hue'       => new NumberNode($hwb->hValue(), 'deg'),
            'whiteness' => new NumberNode($hwb->wValue(), '%'),
            'blackness' => new NumberNode($hwb->bValue(), '%'),
            'alpha'     => new NumberNode($hwb->a),
            default     => throw new UnknownColorChannelException('HWB', $channelName),
        };
    }

    private function resolveLchChannel(AstNode $color, string $channelName): NumberNode
    {
        $rgb = $this->converter->toRgb($color);
        $lch = $this->runtime->spaceConverter->rgbToLch($rgb);

        return match ($channelName) {
            'lightness' => new NumberNode($lch->lValue(), '%'),
            'chroma'    => new NumberNode($lch->cValue()),
            'hue'       => new NumberNode($lch->hValue(), 'deg'),
            'alpha'     => new NumberNode($rgb->a),
            default     => throw new UnknownColorChannelException('LCH', $channelName),
        };
    }

    private function resolveLabChannel(AstNode $color, string $channelName): NumberNode
    {
        $alpha = $this->converter->toAlpha($color);
        $lab   = $this->runtime->spaceConverter->xyzD50ToLabColor($this->converter->toXyzD50($color), $alpha);

        return match ($channelName) {
            'lightness' => new NumberNode($lab->lValue(), '%'),
            'a'         => new NumberNode($lab->aValue()),
            'b'         => new NumberNode($lab->bValue()),
            'alpha'     => new NumberNode($lab->alpha),
            default     => throw new UnknownColorChannelException('Lab', $channelName),
        };
    }

    private function resolveOklchChannel(AstNode $color, string $channelName): NumberNode
    {
        $oklch = $this->runtime->spaceConverter->rgbToOklch($this->converter->toRgb($color));

        return match ($channelName) {
            'lightness' => new NumberNode($oklch->lValue(), '%'),
            'chroma'    => new NumberNode($oklch->cValue()),
            'hue'       => new NumberNode($oklch->hValue(), 'deg'),
            'alpha'     => new NumberNode($oklch->a),
            default     => throw new UnknownColorChannelException('OKLCh', $channelName),
        };
    }

    private function resolveOklabChannel(AstNode $color, string $channelName): NumberNode
    {
        $alpha = $this->converter->toAlpha($color);
        $oklab = $this->runtime->spaceConverter->xyzD65ToOklabColor($this->converter->toXyzD65($color), $alpha);

        return match ($channelName) {
            'lightness' => new NumberNode($oklab->lValue(), '%'),
            'a'         => new NumberNode($oklab->aValue()),
            'b'         => new NumberNode($oklab->bValue()),
            'alpha'     => new NumberNode($oklab->alpha),
            default     => throw new UnknownColorChannelException('OKLab', $channelName),
        };
    }

    private function resolveXyzD65Channel(AstNode $color, string $channelName): NumberNode
    {
        $rgb = $this->converter->toRgb($color);
        $xyz = $this->runtime->spaceConverter->rgbToXyzD65($rgb);

        return match ($channelName) {
            'x'     => new NumberNode($xyz->x),
            'y'     => new NumberNode($xyz->y),
            'z'     => new NumberNode($xyz->z),
            'alpha' => new NumberNode($rgb->a),
            default => throw new UnknownColorChannelException('XYZ', $channelName),
        };
    }

    private function resolveXyzD50Channel(AstNode $color, string $channelName): NumberNode
    {
        $rgb = $this->converter->toRgb($color);
        $xyz = $this->runtime->spaceConverter->rgbToXyzD50($rgb);

        return match ($channelName) {
            'x'     => new NumberNode($xyz->x),
            'y'     => new NumberNode($xyz->y),
            'z'     => new NumberNode($xyz->z),
            'alpha' => new NumberNode($rgb->a),
            default => throw new UnknownColorChannelException('XYZ-D50', $channelName),
        };
    }
}
