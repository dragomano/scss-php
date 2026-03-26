<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\HwbColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstParser;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Contracts\Color\ColorLiteralInterface;
use Bugo\SCSS\Contracts\Color\ColorValueInterface;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Closure;

use function in_array;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

final readonly class ColorNodeConverter
{
    /**
     * @param Closure(string): string $errorCtx
     */
    public function __construct(
        private HexColorConverter $hexColorConverter,
        private CssColorFunctionConverter $cssColorFunctionConverter,
        private ColorConverterInterface $colorSpaceConverter,
        private ColorLiteralInterface $colorLiteralConverter,
        private ColorAstParser $colorAstParser,
        private Closure $errorCtx
    ) {}

    public function toRgb(AstNode $color): RgbColor
    {
        if ($color instanceof FunctionNode) {
            $rgba = $this->cssColorFunctionConverter->tryConvertToRgba($color);

            if ($rgba === null) {
                $errorValue = strtolower($color->name) === 'color'
                    ? $this->detectGenericColorSpace($color)
                    : $color->name;

                throw new UnsupportedColorValueException($errorValue);
            }

            return new RgbColor(
                r: $rgba->rValue() * 255.0,
                g: $rgba->gValue() * 255.0,
                b: $rgba->bValue() * 255.0,
                a: $rgba->a
            );
        }

        if (! ($color instanceof ColorNode || $color instanceof StringNode)) {
            throw new MissingFunctionArgumentsException(($this->errorCtx)('color'), 'color arguments');
        }

        $parsed = $this->colorLiteralConverter->parse($color->value);

        if ($parsed !== null) {
            /** @var ColorValueInterface<RgbColor> $parsed */
            return $parsed->getInner();
        }

        if ($color instanceof StringNode) {
            $parsedColor = $this->colorAstParser->parse($color->value);

            if ($parsedColor !== null) {
                return $this->toRgb($parsedColor);
            }
        }

        throw new UnsupportedColorValueException(strtolower($color->value));
    }

    public function toAlpha(AstNode $color): float
    {
        if ($color instanceof FunctionNode) {
            $xyz = $this->toXyzD65WithAlpha($color);

            if ($xyz !== null) {
                return $xyz[1];
            }
        }

        return $this->toRgb($color)->a;
    }

    public function toXyzD65(AstNode $color): XyzColor
    {
        if ($color instanceof FunctionNode) {
            $xyz = $this->toXyzD65WithAlpha($color);

            if ($xyz !== null) {
                return $xyz[0];
            }
        }

        return $this->colorSpaceConverter->rgbToXyzD65($this->toRgb($color));
    }

    public function toXyzD50(AstNode $color): XyzColor
    {
        if ($color instanceof FunctionNode) {
            $xyz = $this->cssColorFunctionConverter->tryConvertToXyzD50($color);

            if ($xyz !== null) {
                return $xyz[0];
            }
        }

        return $this->colorSpaceConverter->rgbToXyzD50($this->toRgb($color));
    }

    /**
     * @return array{0: XyzColor, 1: float}|null
     */
    public function toXyzD65WithAlpha(FunctionNode $color): ?array
    {
        return $this->cssColorFunctionConverter->tryConvertToXyzD65($color);
    }

    public function toHsl(AstNode $color): HslColor
    {
        return $this->colorSpaceConverter->rgbToHslColor($this->toRgb($color));
    }

    public function toHwb(AstNode $color): HwbColor
    {
        $rgb = $this->toRgb($color);
        $r   = $rgb->rValue() / 255.0;
        $g   = $rgb->gValue() / 255.0;
        $b   = $rgb->bValue() / 255.0;

        $max   = max($r, $g, $b);
        $min   = min($r, $g, $b);
        $delta = $max - $min;

        return new HwbColor(
            h: $this->colorSpaceConverter->hueFromNormalizedRgb($r, $g, $b, $max, $min, $delta),
            w: $min * 100.0,
            b: (1.0 - $max) * 100.0,
            a: $rgb->a
        );
    }

    public function parseColorString(string $value): FunctionNode|ColorNode|null
    {
        return $this->colorAstParser->parse($value);
    }

    public function detectNativeColorSpace(AstNode $color): string
    {
        if ($color instanceof FunctionNode) {
            return match (strtolower($color->name)) {
                'hwb', 'hwba'     => 'hwb',
                'lab', 'laba'     => 'lab',
                'lch', 'lcha'     => 'lch',
                'oklab', 'oklaba' => 'oklab',
                'oklch', 'oklcha' => 'oklch',
                'color'           => $this->detectGenericColorSpace($color),
                default           => 'hsl',
            };
        }

        return 'rgb';
    }

    public function detectGenericColorSpace(FunctionNode $color): string
    {
        $arguments = $this->hexColorConverter->expandArguments($color);
        $spaceNode = $arguments[0] ?? null;

        if (! ($spaceNode instanceof StringNode)) {
            return 'srgb';
        }

        return match (strtolower($spaceNode->value)) {
            'xyz-d65' => 'xyz',
            default   => strtolower($spaceNode->value),
        };
    }

    /**
     * @return array<int, AstNode>
     */
    public function extractChannelNodes(FunctionNode $fn): array
    {
        $items      = $this->hexColorConverter->expandArguments($fn);
        [$channels] = $this->hexColorConverter->splitChannelsAndAlpha($items, false);

        return $channels;
    }

    public function isInGamut(AstNode $color): bool
    {
        if ($this->isLegacyColor($color)) {
            return true;
        }

        if (! ($color instanceof FunctionNode)) {
            return true;
        }

        $name = strtolower($color->name);

        if (in_array($name, ['lab', 'lch', 'oklab', 'oklch'], true)) {
            return true;
        }

        if ($name !== 'color') {
            return true;
        }

        $space = $this->detectGenericColorSpace($color);

        if (in_array($space, ['xyz', 'xyz-d50', 'xyz-d65'], true)) {
            return true;
        }

        $nodes = $this->extractChannelNodes($color);

        for ($i = 1; $i <= 3; $i++) {
            $node = $nodes[$i] ?? null;

            if ($node === null) {
                continue;
            }

            if ($node instanceof StringNode && strtolower($node->value) === 'none') {
                continue;
            }

            if (! ($node instanceof NumberNode)) {
                continue;
            }

            $val = (float) $node->value;

            if ($node->unit === '%') {
                $val /= 100.0;
            }

            if ($val < -1e-10 || $val > 1.0 + 1e-10) {
                return false;
            }
        }

        return true;
    }

    public function isLegacyColor(AstNode $color): bool
    {
        if ($color instanceof ColorNode) {
            return true;
        }

        if ($color instanceof FunctionNode) {
            return in_array(strtolower($color->name), ['rgb', 'rgba', 'hsl', 'hsla', 'hwb'], true);
        }

        if ($color instanceof StringNode) {
            $value = strtolower(trim($color->value));

            if (str_starts_with($value, '#') || ! str_contains($value, '(')) {
                return true;
            }

            foreach (['rgb(', 'rgba(', 'hsl(', 'hsla(', 'hwb('] as $prefix) {
                if (str_starts_with($value, $prefix)) {
                    return true;
                }
            }

            return false;
        }

        return false;
    }
}
