<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\OklabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

use function count;
use function in_array;
use function max;
use function round;
use function sprintf;
use function str_contains;
use function strtolower;

final readonly class ColorConstructorEvaluator
{
    private const SUPPORTED_COLOR_SPACES = [
        'srgb',
        'srgb-linear',
        'display-p3',
        'display-p3-linear',
        'a98-rgb',
        'prophoto-rgb',
        'rec2020',
        'xyz',
        'xyz-d50',
        'xyz-d65',
    ];

    public function __construct(
        private ColorArgumentParser $parser,
        private ColorNodeConverter $converter,
        private ColorModuleContext $context,
    ) {}

    /**
     * @param array<int, AstNode> $positional
     */
    public function hslFunction(array $positional): AstNode
    {
        $arguments    = $this->parser->parseFunctionalColorArguments($positional, 'hsl', 3, true);
        $hueMissing   = $this->parser->isMissingChannelNode($arguments[0]);
        $satMissing   = $this->parser->isMissingChannelNode($arguments[1]);
        $lightMissing = $this->parser->isMissingChannelNode($arguments[2]);

        if ($hueMissing || $satMissing || $lightMissing) {
            return new FunctionNode('hsl', [
                new ListNode([
                    $hueMissing ? new StringNode('none') : new NumberNode(
                        $this->parser->normalizeHue(
                            $this->parser->asNumber($arguments[0], 'hsl'),
                        ),
                        'deg',
                    ),
                    $satMissing ? new StringNode('none') : new NumberNode(
                        max(0.0, $this->parser->asPercentage($arguments[1], 'hsl')),
                        '%',
                    ),
                    $lightMissing ? new StringNode('none') : new NumberNode(
                        $this->parser->asPercentage($arguments[2], 'hsl'),
                        '%',
                    ),
                ], 'space'),
            ]);
        }

        return $this->converter->buildHslFunctionNode(
            $this->parser->normalizeHue($this->parser->asNumber($arguments[0], 'hsl')),
            max(0.0, $this->parser->asPercentage($arguments[1], 'hsl')),
            $this->parser->asPercentage($arguments[2], 'hsl'),
            $this->parser->parseAlphaOrDefault($arguments, 3, 'hsl'),
        );
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function hslaFunction(array $positional): FunctionNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'hsla', 4);

        return $this->converter->buildHslFunctionNode(
            $this->parser->normalizeHue($this->parser->asNumber($arguments[0], 'hsla')),
            max(0.0, $this->parser->asPercentage($arguments[1], 'hsla')),
            $this->parser->asPercentage($arguments[2], 'hsla'),
            $this->parser->parseAlphaOrDefault($arguments, 3, 'hsla'),
        );
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function rgbFunction(array $positional, array $named = []): AstNode
    {
        if (isset($named['red'], $named['green'], $named['blue'])) {
            return $this->converter->buildRgbFunctionNode(
                $this->parser->asByte($named['red'], 'rgb'),
                $this->parser->asByte($named['green'], 'rgb'),
                $this->parser->asByte($named['blue'], 'rgb'),
                isset($named['alpha']) ? $this->parser->parseAlphaNode($named['alpha'], 'rgb') : 1.0,
            );
        }

        $alphaNode = $positional[1] ?? $named['alpha'] ?? null;

        if (isset($positional[0]) && count($positional) <= 2 && $alphaNode instanceof NumberNode) {
            try {
                return $this->colorWithAlpha(
                    $this->parser->requireColor($positional, 0, 'rgb'),
                    $this->parser->parseAlphaNode($alphaNode, 'rgb'),
                );
            } catch (MissingFunctionArgumentsException|UnsupportedColorValueException $e) {
                if (! $this->isUnresolvableCssValue($positional[0])) {
                    throw $e;
                }

                return new FunctionNode('rgb', [$positional[0], $alphaNode]);
            }
        }

        if (count($positional) === 2) {
            try {
                $rgb = $this->converter->toRgb($this->parser->requireColor($positional, 0, 'rgb'));
            } catch (MissingFunctionArgumentsException|UnsupportedColorValueException) {
                $rgb = null;
            }

            if ($rgb !== null) {
                return new FunctionNode('rgb', [
                    new NumberNode($rgb->rValue()),
                    new NumberNode($rgb->gValue()),
                    new NumberNode($rgb->bValue()),
                    $positional[1],
                ]);
            }
        }

        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'rgb', 3);

        return $this->converter->buildRgbFunctionNode(
            $this->parser->asByte($arguments[0], 'rgb'),
            $this->parser->asByte($arguments[1], 'rgb'),
            $this->parser->asByte($arguments[2], 'rgb'),
            $this->parser->parseAlphaOrDefault($arguments, 3, 'rgb'),
        );
    }

    private function colorWithAlpha(AstNode $color, float $alpha): AstNode
    {
        $rgb = $this->converter->toRgb($color);

        return $this->converter->serializeRgbResult(new RgbColor(
            r: $rgb->r,
            g: $rgb->g,
            b: $rgb->b,
            a: $alpha,
        ));
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function rgbaFunction(array $positional, array $named = []): AstNode
    {
        if ($this->parser->isRelativeColorSyntax($positional)) {
            throw new DeferToCssFunctionException(
                $this->parser->callRef('rgba') . ' should be emitted as a CSS function.',
            );
        }

        if (isset($named['red'], $named['green'], $named['blue'])) {
            return $this->converter->buildRgbFunctionNode(
                $this->parser->asByte($named['red'], 'rgba'),
                $this->parser->asByte($named['green'], 'rgba'),
                $this->parser->asByte($named['blue'], 'rgba'),
                isset($named['alpha']) ? $this->parser->parseAlphaNode($named['alpha'], 'rgba') : 1.0,
            );
        }

        $alphaNode = $positional[1] ?? $named['alpha'] ?? null;

        if (isset($positional[0]) && count($positional) <= 2 && $alphaNode instanceof NumberNode) {
            try {
                return $this->colorWithAlpha(
                    $this->parser->requireColor($positional, 0, 'rgba'),
                    $this->parser->parseAlphaNode($alphaNode, 'rgba'),
                );
            } catch (MissingFunctionArgumentsException|UnsupportedColorValueException $e) {
                if (! $this->isUnresolvableCssValue($positional[0])) {
                    throw $e;
                }

                return new FunctionNode('rgba', [$positional[0], $alphaNode]);
            }
        }

        if (count($positional) === 2) {
            try {
                $rgb = $this->converter->toRgb($this->parser->requireColor($positional, 0, 'rgba'));
            } catch (MissingFunctionArgumentsException|UnsupportedColorValueException) {
                $rgb = null;
            }

            if ($rgb !== null) {
                return new FunctionNode('rgba', [
                    new NumberNode($rgb->rValue()),
                    new NumberNode($rgb->gValue()),
                    new NumberNode($rgb->bValue()),
                    $positional[1],
                ]);
            }
        }

        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'rgba', 3);

        return $this->converter->buildRgbFunctionNode(
            $this->parser->asByte($arguments[0], 'rgba'),
            $this->parser->asByte($arguments[1], 'rgba'),
            $this->parser->asByte($arguments[2], 'rgba'),
            $this->parser->parseAlphaOrDefault($arguments, 3, 'rgba'),
        );
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function legacyRgbaFunction(array $positional, array $named = []): AstNode
    {
        if ($this->parser->isRelativeColorSyntax($positional)) {
            return new FunctionNode('rgba', $positional);
        }

        try {
            return $this->rgbaFunction($positional, $named);
        } catch (MissingFunctionArgumentsException|UnsupportedColorValueException|DeferToCssFunctionException) {
            return new FunctionNode('rgba', $positional);
        }
    }

    private function isUnresolvableCssValue(AstNode $value): bool
    {
        if ($value instanceof FunctionNode) {
            return in_array(strtolower($value->name), ['var', 'env'], true);
        }

        if ($value instanceof StringNode) {
            return str_contains(strtolower($value->value), '(');
        }

        return false;
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function hwbFunction(array $positional): AstNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'hwb', 3);
        $hue       = $this->parser->normalizeHue($this->parser->asNumber($arguments[0], 'hwb'));
        $whiteness = $this->parser->asPercentage($arguments[1], 'hwb');
        $blackness = $this->parser->asPercentage($arguments[2], 'hwb');
        $alpha     = $this->parser->parseAlphaOrDefault($arguments, 3, 'hwb');

        $sum = $whiteness + $blackness;
        if ($sum > 100.0) {
            $whiteness = ($whiteness / $sum) * 100.0;
            $blackness = ($blackness / $sum) * 100.0;
        }

        return $this->converter->buildFunctionalColorNode('hwb', [
            new NumberNode($hue),
            new NumberNode($whiteness, '%'),
            new NumberNode($blackness, '%'),
        ], $alpha);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function colorFunction(array $positional): AstNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'color', 4);
        $space     = strtolower($this->parser->asString($arguments[0], 'color'));
        $ch1       = $this->parser->asColorChannel($arguments[1]);
        $ch2       = $this->parser->asColorChannel($arguments[2]);
        $ch3       = $this->parser->asColorChannel($arguments[3]);
        $alpha     = $this->parser->parseAlphaOrDefault($arguments, 4, 'color');

        if (! in_array($space, self::SUPPORTED_COLOR_SPACES, true)) {
            throw new UnsupportedColorSpaceException($space, $this->context->errorCtx('color'));
        }

        return $this->converter->buildGenericColorFunctionNode($space, [$ch1, $ch2, $ch3], $alpha);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function labFunction(array $positional): AstNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'lab', 3);
        $lightness = $this->parser->asPercentage($arguments[0], 'lab');
        $a         = $this->parser->asAbsoluteChannel($arguments[1], 'lab', 125.0);
        $b         = $this->parser->asAbsoluteChannel($arguments[2], 'lab', 125.0);
        $alpha     = $this->parser->parseAlphaOrDefault($arguments, 3, 'lab');

        return $this->converter->buildLabColorNode(new LabColor($lightness, $a, $b, $alpha));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function lchFunction(array $positional): AstNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'lch', 3);
        $lightness = $this->parser->asPercentage($arguments[0], 'lch');
        $chroma    = $this->parser->asAbsoluteChannel($arguments[1], 'lch', 150.0);
        $hue       = $this->parser->normalizeHue($this->parser->asHueAngle($arguments[2], 'lch'));
        $alpha     = $this->parser->parseAlphaOrDefault($arguments, 3, 'lch');

        return $this->converter->buildLchColorNode(new LchColor($lightness, $chroma, $hue), $alpha);
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function oklabFunction(array $positional): AstNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'oklab', 3);
        $lightness = $this->parser->asPercentage($arguments[0], 'oklab');
        $a         = $this->parser->asAbsoluteChannel($arguments[1], 'oklab', 0.4);
        $b         = $this->parser->asAbsoluteChannel($arguments[2], 'oklab', 0.4);
        $alpha     = $this->parser->parseAlphaOrDefault($arguments, 3, 'oklab');

        return $this->converter->buildOklabColorNode(new OklabColor($lightness, $a, $b, $alpha));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function oklchFunction(array $positional): AstNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'oklch', 3);
        $lightness = $this->parser->asPercentage($arguments[0], 'oklch');
        $chroma    = $this->parser->asAbsoluteChannel($arguments[1], 'oklch', 0.4);
        $hue       = $this->parser->normalizeHue($this->parser->asHueAngle($arguments[2], 'oklch'));
        $alpha     = $this->parser->parseAlphaOrDefault($arguments, 3, 'oklch');

        return $this->converter->serializeAsOklchString(new OklchColor($lightness, $chroma, $hue, $alpha));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function ieHexStr(array $positional): StringNode
    {
        $rgb = $this->converter->toRgb(
            $this->parser->requireColor($positional, 0, 'ie-hex-str'),
        );

        return new StringNode(sprintf(
            '#%02X%02X%02X%02X',
            (int) round($rgb->a * 255.0),
            (int) round($rgb->rValue()),
            (int) round($rgb->gValue()),
            (int) round($rgb->bValue()),
        ));
    }
}
