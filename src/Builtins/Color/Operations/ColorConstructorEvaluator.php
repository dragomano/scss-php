<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\OklabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\DartColorMath;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorModuleContext;
use Bugo\SCSS\Exceptions\DeferToCssFunctionException;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnsupportedColorSpaceException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

use function count;
use function in_array;
use function is_finite;
use function is_nan;
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

    private const RGB_CHANNEL_NAMES = ['red', 'green', 'blue'];

    private const HSL_CHANNEL_NAMES = ['hue', 'saturation', 'lightness'];

    public function __construct(
        private ColorArgumentParser $parser,
        private ColorNodeConverter $converter,
        private ColorModuleContext $context,
        private DartColorMath $colorMath = new DartColorMath(),
    ) {}

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function hslFunction(array $positional, array $named = []): AstNode
    {
        $positional = $this->mergeNamedChannelArguments(
            $positional,
            $named,
            [...self::HSL_CHANNEL_NAMES, 'alpha'],
        );

        $input = $this->singleChannelsInput($positional, $named);

        if ($input !== null) {
            return $this->colorFromChannels('hsl', $input);
        }

        $arguments    = $this->parser->parseFunctionalColorArguments($positional, 'hsl', 3, true);
        $hueMissing   = $this->parser->isMissingChannelNode($arguments[0]);
        $satMissing   = $this->parser->isMissingChannelNode($arguments[1]);
        $lightMissing = $this->parser->isMissingChannelNode($arguments[2]);

        if ($hueMissing || $satMissing || $lightMissing) {
            return $this->converter->buildModernHslFunctionNode([
                $hueMissing ? null : $this->parser->normalizeHue($this->parser->asNumber($arguments[0], 'hsl')),
                $satMissing ? null : $this->clampSaturation($this->parser->asPercentage($arguments[1], 'hsl')),
                $lightMissing ? null : $this->parser->asPercentage($arguments[2], 'hsl'),
            ], 1.0);
        }

        return $this->buildHslColorFromNodes(
            [$arguments[0], $arguments[1], $arguments[2]],
            $this->parser->parseAlphaOrDefault($arguments, 3, 'hsl'),
            'hsl',
        );
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function hslaFunction(array $positional, array $named = []): AstNode
    {
        $positional = $this->mergeNamedChannelArguments(
            $positional,
            $named,
            [...self::HSL_CHANNEL_NAMES, 'alpha'],
        );

        $input = $this->singleChannelsInput($positional, $named);

        if ($input !== null) {
            return $this->colorFromChannels('hsla', $input);
        }

        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'hsla', 3);

        return $this->buildHslColorFromNodes(
            [$arguments[0], $arguments[1], $arguments[2]],
            $this->parser->parseAlphaOrDefault($arguments, 3, 'hsla'),
            'hsla',
        );
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

        $positional = $this->mergeNamedChannelArguments(
            $positional,
            $named,
            [...self::RGB_CHANNEL_NAMES, 'alpha'],
        );

        if (isset($named['red'], $named['green'], $named['blue'])) {
            return $this->converter->buildRgbFunctionNode(
                $this->parser->asByte($named['red'], 'rgba'),
                $this->parser->asByte($named['green'], 'rgba'),
                $this->parser->asByte($named['blue'], 'rgba'),
                isset($named['alpha']) ? $this->parser->parseAlphaNode($named['alpha'], 'rgba') : 1.0,
            );
        }

        $input = $this->singleChannelsInput($positional, $named);

        if ($input !== null) {
            return $this->colorFromChannels('rgba', $input);
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

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function rgbFunction(array $positional, array $named = []): AstNode
    {
        $positional = $this->mergeNamedChannelArguments(
            $positional,
            $named,
            [...self::RGB_CHANNEL_NAMES, 'alpha'],
        );

        if (isset($named['red'], $named['green'], $named['blue'])) {
            return $this->converter->buildRgbFunctionNode(
                $this->parser->asByte($named['red'], 'rgb'),
                $this->parser->asByte($named['green'], 'rgb'),
                $this->parser->asByte($named['blue'], 'rgb'),
                isset($named['alpha']) ? $this->parser->parseAlphaNode($named['alpha'], 'rgb') : 1.0,
            );
        }

        $input = $this->singleChannelsInput($positional, $named);

        if ($input !== null) {
            return $this->colorFromChannels('rgb', $input);
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


    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function hwbFunction(array $positional, array $named = []): AstNode
    {
        if (isset($named['channels']) && $positional === []) {
            $positional[] = $named['channels'];
        } else {
            $positional = $this->mergeNamedChannelArguments(
                $positional,
                $named,
                ['hue', 'whiteness', 'blackness', 'alpha'],
            );
        }

        $firstPositional = $positional[0] ?? null;

        if ($firstPositional instanceof ListNode && $firstPositional->separator === 'slash') {
            $channelList = $this->parser->parseChannelList($firstPositional);

            if ($channelList !== null && $channelList['components'] instanceof ListNode) {
                $positional = [
                    ...$channelList['components']->items,
                    new StringNode('/'),
                    ...($channelList['alpha'] === null ? [] : [$channelList['alpha']]),
                ];
            }
        }

        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'hwb', 3);

        if (isset($arguments[3]) && $this->parser->isMissingChannelNode($arguments[3])) {
            return new FunctionNode('hwb', [new ListNode([
                $arguments[0] instanceof NumberNode && $arguments[0]->value == 0
                    ? new StringNode('0.0deg')
                    : $arguments[0],
                $arguments[1],
                $arguments[2],
                new StringNode('/'),
                $arguments[3],
            ], 'space')]);
        }

        $hue = $this->parser->isMissingChannelNode($arguments[0])
            ? 0.0
            : $this->parser->normalizeHue($this->parser->asHueAngle($arguments[0], 'hwb'));

        if (
            $this->parser->isMissingChannelNode($arguments[0])
            || $this->parser->isMissingChannelNode($arguments[1])
            || $this->parser->isMissingChannelNode($arguments[2])
        ) {
            $channels = [
                new StringNode('0.0deg'),
                $arguments[1],
                $arguments[2],
            ];

            if (isset($arguments[3])) {
                $channels[] = new StringNode('/');
                $channels[] = $arguments[3];
            }

            return new FunctionNode('hwb', [new ListNode($channels, 'space')]);
        }

        $whiteness = $this->parser->asPercentage($arguments[1], 'hwb');
        $blackness = $this->parser->asPercentage($arguments[2], 'hwb');
        $alpha     = $this->parser->parseAlphaOrDefault($arguments, 3, 'hwb');

        $sum = $whiteness + $blackness;

        if ($sum > 100.0) {
            $whiteness = ($whiteness / $sum) * 100.0;
            $blackness = ($blackness / $sum) * 100.0;

            $whiteness = is_nan($whiteness) ? 0.0 : $whiteness;
            $blackness = is_nan($blackness) ? 0.0 : $blackness;
        }

        if (! is_finite($whiteness) || ! is_finite($blackness)) {
            return $this->converter->buildHslFunctionNode(0.0, 0.0, 0.0, $alpha);
        }

        [$red, $green, $blue] = $this->colorMath->hwbToSrgb($hue, $whiteness, $blackness);

        $red   *= 255.0;
        $green *= 255.0;
        $blue  *= 255.0;

        if (abs($alpha - 1.0) < 0.0000001
            && abs($red - round($red)) < 0.0000001
            && abs($green - round($green)) < 0.0000001
            && abs($blue - round($blue)) < 0.0000001
        ) {
            return $this->converter->fromRgb(new RgbColor($red, $green, $blue, $alpha));
        }

        $collapsed = $this->converter->serializeAsUnclampedHsl(
            $red,
            $green,
            $blue,
            $alpha,
        );

        $collapsed->originColorSpace   = 'hwb';
        $collapsed->originSrgbChannels = [$red / 255.0, $green / 255.0, $blue / 255.0];

        return $collapsed;
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

        if (! ($arguments[0] instanceof NumberNode) || $arguments[0]->unit !== '%') {
            $lightness *= 100.0;
        }

        $a     = $this->parser->asAbsoluteChannel($arguments[1], 'oklab', 0.4);
        $b     = $this->parser->asAbsoluteChannel($arguments[2], 'oklab', 0.4);
        $alpha = $this->parser->parseAlphaOrDefault($arguments, 3, 'oklab');

        return $this->converter->buildOklabColorNode(new OklabColor($lightness, $a, $b, $alpha));
    }

    /**
     * @param array<int, AstNode> $positional
     */
    public function oklchFunction(array $positional): AstNode
    {
        $arguments = $this->parser->parseFunctionalColorArguments($positional, 'oklch', 3);
        $lightness = $this->parser->asPercentage($arguments[0], 'oklch');

        if (! ($arguments[0] instanceof NumberNode) || $arguments[0]->unit !== '%') {
            $lightness *= 100.0;
        }

        $chroma = $this->parser->asAbsoluteChannel($arguments[1], 'oklch', 0.4);
        $hue    = $this->parser->normalizeHue($this->parser->asHueAngle($arguments[2], 'oklch'));
        $alpha  = $this->parser->parseAlphaOrDefault($arguments, 3, 'oklch');

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
    private function singleChannelsInput(array $positional, array $named): ?AstNode
    {
        if (isset($named['channels']) && $positional === []) {
            return $named['channels'] instanceof ColorNode ? null : $named['channels'];
        }

        if (count($positional) === 1 && ! ($positional[0] instanceof ColorNode)) {
            return $positional[0];
        }

        return null;
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     * @param list<string> $names
     * @return array<int, AstNode>
     */
    private function mergeNamedChannelArguments(array $positional, array $named, array $names): array
    {
        if ($named === []) {
            return $positional;
        }

        $merged = [];

        foreach ($names as $index => $name) {
            $node = $positional[$index] ?? $named[$name] ?? null;

            if ($node !== null) {
                $merged[] = $node;
            }
        }

        return $merged;
    }

    private function colorFromChannels(string $function, AstNode $input): AstNode
    {
        $isHsl = $function === 'hsl' || $function === 'hsla';
        $space = $isHsl ? 'hsl' : 'rgb';

        $parsed = $this->parser->parseChannels(
            $function,
            'channels',
            $space,
            $isHsl ? self::HSL_CHANNEL_NAMES : self::RGB_CHANNEL_NAMES,
            $input,
        );

        if ($parsed->isCommaForm()) {
            return new FunctionNode($function, $parsed->commaArguments);
        }

        $channels   = $parsed->channels;
        $alphaValue = $parsed->alphaValue;

        if ($alphaValue === null || $this->hasMissingChannel($channels)) {
            return $isHsl
                ? $this->converter->buildModernHslFunctionNode($this->hslChannelFloats($channels), $alphaValue)
                : $this->converter->buildModernRgbFunctionNode($this->rgbChannelFloats($channels), $alphaValue);
        }

        if ($isHsl) {
            return $this->buildHslColorFromNodes($channels, $alphaValue, $function);
        }

        return $this->converter->buildRgbFunctionNode(
            $this->parser->asByte($channels[0], $function),
            $this->parser->asByte($channels[1], $function),
            $this->parser->asByte($channels[2], $function),
            $alphaValue,
        );
    }

    /**
     * @param list<AstNode> $channels
     */
    private function hasMissingChannel(array $channels): bool
    {
        foreach ($channels as $channel) {
            if ($this->parser->isMissingChannelNode($channel)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<AstNode> $channels
     * @return array{0: ?float, 1: ?float, 2: ?float}
     */
    private function rgbChannelFloats(array $channels): array
    {
        return [
            $channels[0] instanceof NumberNode ? $this->parser->asByte($channels[0], 'rgb') : null,
            $channels[1] instanceof NumberNode ? $this->parser->asByte($channels[1], 'rgb') : null,
            $channels[2] instanceof NumberNode ? $this->parser->asByte($channels[2], 'rgb') : null,
        ];
    }

    /**
     * @param list<AstNode> $channels
     * @return array{0: ?float, 1: ?float, 2: ?float}
     */
    private function hslChannelFloats(array $channels): array
    {
        return [
            $channels[0] instanceof NumberNode
                ? $this->parser->normalizeHue($this->parser->asHueAngle($channels[0], 'hsl'))
                : null,
            $channels[1] instanceof NumberNode ? $this->parser->asLenientPercentage($channels[1], 'hsl') : null,
            $channels[2] instanceof NumberNode ? $this->parser->asLenientPercentage($channels[2], 'hsl') : null,
        ];
    }

    /**
     * @param list<AstNode> $channels
     */
    private function buildHslColorFromNodes(array $channels, float $alpha, string $context): AstNode
    {
        return $this->converter->buildHslFunctionNode(
            $this->parser->normalizeHue($this->parser->asHueAngle($channels[0], $context)),
            $this->clampSaturation($this->parser->asLenientPercentage($channels[1], $context)),
            $this->parser->asLenientPercentage($channels[2], $context),
            $alpha,
            true,
        );
    }

    private function clampSaturation(float $value): float
    {
        return is_nan($value) ? 0.0 : max(0.0, $value);
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
}
