<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\Iris\Manipulators\LegacyManipulator;
use Bugo\Iris\Manipulators\PerceptualManipulator;
use Bugo\Iris\Manipulators\SrgbManipulator;
use Bugo\Iris\Operations\ColorMixResolver;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Builtins\Color\Support\LegacyColorMath;
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
use function array_values;
use function count;
use function explode;
use function implode;
use function min;
use function sprintf;
use function strtolower;
use function trim;

final readonly class ColorFunctionEvaluator
{
    public function __construct(
        private ColorRuntime $runtime,
        private LegacyManipulator $legacy,
        private PerceptualManipulator $perceptual,
        private SrgbManipulator $srgb,
        private ColorMixResolver $mixResolver,
        private ColorNodeConverter $converter,
        private ColorSpaceConverter $spaceInterop,
        private LegacyColorMath $legacyMath,
    ) {}

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function adjustColor(array $positional, array $named, string $context = 'adjust-color'): AstNode
    {
        return $this->applyColorModification(
            $positional,
            $named,
            $context,
            fn(float $current, float $delta): float => $current + $delta,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function changeColor(array $positional, array $named): AstNode
    {
        return $this->applyColorModification(
            $positional,
            $named,
            'change-color',
            fn(float $current, float $value): float => $value,
        );
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function scaleColor(array $positional, array $named): AstNode
    {
        $color       = $this->runtime->argumentParser->requireColorOrDefer($positional, 'scale-color');
        $spaceNode   = $named['space'] ?? null;
        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (
            ($spaceNode instanceof StringNode && strtolower($spaceNode->value) === 'oklch')
            || $nativeSpace === 'oklch'
        ) {
            return $this->scaleColorInOklch($color, $named);
        }

        if ($this->isGenericColorFunction($color)) {
            return $this->scaleInGenericColorSpace($color, $named);
        }

        $rgb = $this->converter->toRgb($color);

        $scaledRgb = $this->legacy->scale(
            $rgb,
            $this->runtime->modelConverter->rgbToHslColor($rgb),
            [
                'red'        => $this->parseScalePercentage($named, 'red'),
                'green'      => $this->parseScalePercentage($named, 'green'),
                'blue'       => $this->parseScalePercentage($named, 'blue'),
                'alpha'      => $this->parseScalePercentage($named, 'alpha'),
                'saturation' => $this->parseScalePercentage($named, 'saturation'),
                'lightness'  => $this->parseScalePercentage($named, 'lightness'),
            ],
        );

        return $this->converter->serializeRgbResult($scaledRgb);
    }

    /** @param array<string, AstNode> $named */
    public function scaleColorInOklch(AstNode $color, array $named): AstNode
    {
        $oklch = $this->extractNativeOrConvertedOklchColor($color);

        $scaled = new OklchColor(
            l: $this->runtime->spaceConverter->scaleLinear(
                $oklch->lValue(),
                $this->parseScalePercentage($named, 'lightness') ?? 0.0,
                100.0,
            ),
            c: $this->runtime->spaceConverter->scaleLinear(
                $oklch->cValue(),
                $this->parseScalePercentage($named, 'chroma') ?? 0.0,
                0.4,
            ),
            h: $oklch->h,
            a: $this->runtime->spaceConverter->scaleLinear(
                $oklch->a,
                $this->parseScalePercentage($named, 'alpha') ?? 0.0,
                1.0,
            ),
        );

        if ($this->converter->detectNativeColorSpace($color) === 'oklch') {
            return $this->converter->serializeAsOklchString($scaled);
        }

        return $this->converter->serializeAsFloatRgb($this->runtime->spaceConverter->oklchToRgb($scaled));
    }

    /** @param array<int, AstNode> $positional */
    public function adjustHue(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $color   = $this->runtime->argumentParser->requireColorOrDefer($positional, 'adjust-hue');
        $degrees = $this->runtime->argumentParser->asNumber($positional[1] ?? null, 'adjust-hue');

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

    /** @param array<int, AstNode> $positional */
    public function complement(array $positional): AstNode
    {
        $color = $this->runtime->argumentParser->requireColorOrDefer($positional, 'complement');
        $space = isset($positional[1])
            ? strtolower($this->runtime->argumentParser->asString($positional[1], 'complement'))
            : null;

        $named = ['hue' => new NumberNode(180)];

        if ($space !== null) {
            $named['space'] = new StringNode($space);
        }

        if ($space === null && $this->converter->isLegacyColor($color)) {
            return $this->emitModifiedLegacyColor(
                $color,
                fn(array $channels): array => $this->legacyMath->shiftChannel($channels, 'h', 180.0),
            );
        }

        return $this->applyColorModification(
            [$color],
            $named,
            'complement',
            fn(float $current, float $delta): float => $current + $delta,
        );
    }

    /** @param array<int, AstNode> $positional */
    public function grayscale(array $positional): AstNode
    {
        $color = $this->runtime->argumentParser->requireColorOrDefer($positional, 'grayscale');

        if ($this->converter->isLegacyColor($color)) {
            return $this->emitModifiedLegacyColor(
                $color,
                function (array $channels): array {
                    $channels['s'] = 0.0;

                    return $channels;
                },
            );
        }

        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if ($nativeSpace === 'oklch' && $color instanceof FunctionNode) {
            $oklch = $this->converter->readNativeOklch($color);

            return $this->converter->serializeAsOklchString(
                new OklchColor(
                    l: $oklch->l,
                    c: 0.0,
                    h: $oklch->h,
                    a: 1.0,
                ),
                true,
            );
        }

        $rgb       = $this->converter->toRgb($color);
        $oklch     = $this->createOklchFromRgb($rgb);
        $grayOklch = new OklchColor(l: $oklch->l, c: 0.0, h: $oklch->h, a: $rgb->a);
        $grayRgb   = $this->runtime->spaceConverter->oklchToRgb($grayOklch);

        if ($nativeSpace === 'srgb') {
            return $this->converter->serializeAsSrgbString(
                $grayRgb->rValue(),
                $grayRgb->gValue(),
                $grayRgb->bValue(),
            );
        }

        return $this->converter->serializeAsFloatRgb($grayRgb);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function mix(array $positional, array $named): AstNode
    {
        $color1 = $this->runtime->argumentParser->requireColorOrDefer($positional, 'mix');
        $color2 = $this->runtime->argumentParser->requireColor($positional, 1, 'mix');
        $weight = $this->runtime->argumentParser->asPercentage(
            $named['weight'] ?? ($positional[2] ?? new NumberNode(50)),
            'mix',
        );

        ['space' => $method, 'hue' => $hueMethod] = $this->resolveMixMethod($named, $positional);

        $rgb1 = $this->converter->toRgb($color1);
        $rgb2 = $this->converter->toRgb($color2);
        $p    = $this->runtime->argumentParser->clamp($weight / 100.0, 1.0);

        if ($method === 'hsl') {
            $result = $this->mixInHslSpace($color1, $color2, $p, $hueMethod);

            if ($result !== null) {
                return $result;
            }
        }

        if ($method === 'rec2020') {
            return $this->mixColorSpaceChannels($color1, $color2, $p);
        }

        if ($method === 'oklch') {
            return $this->mixInOklchSpace($color1, $color2, $p, $hueMethod);
        }

        $mixedRgbInner = $this->legacy->mix($rgb1, $rgb2, $p);

        return $this->converter->serializeRgbResult($mixedRgbInner);
    }

    /** @param array<int, AstNode> $positional */
    public function same(array $positional): BooleanNode
    {
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

        $weight = $this->runtime->argumentParser->asPercentage(
            $named['weight'] ?? ($positional[1] ?? new NumberNode(100)),
            'invert',
        );

        $p   = $this->runtime->argumentParser->clamp($weight / 100.0, 1.0);
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

        $invertedRgb = $this->legacy->invert($rgb, $p);
        $legacyHsl   = $this->extractLegacyHsl($color);

        if ($legacyHsl !== null && $legacyHsl['origin'] !== 'rgb') {
            return $this->emitLegacyHsl(
                $this->legacyMath->rgbToHsl($invertedRgb),
                $legacyHsl['origin'],
            );
        }

        return $this->converter->serializeRgbResult($invertedRgb);
    }

    public function extractNativeOrConvertedOklchColor(AstNode $color): OklchColor
    {
        return $this->converter->extractOklch($color, 'color');
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyColorModification(
        array $positional,
        array $named,
        string $context,
        callable $modify,
    ): AstNode {
        $requestedSpace = null;

        if (isset($named['space'])) {
            $requestedSpace = strtolower($this->runtime->argumentParser->asString($named['space'], $context));
            unset($named['space']);
        }

        $color        = $this->runtime->argumentParser->requireColorOrDefer($positional, $context);
        $isLegacy     = $this->converter->isLegacyColor($color);
        $nativeSpace  = $this->converter->detectNativeColorSpace($color);
        $workingSpace = $requestedSpace ?? $nativeSpace;

        return match ($workingSpace) {
            'oklch' => $this->applyInOklchSpace($color, $named, $modify),
            'oklab' => $this->applyInOklabSpace($color, $named, $modify),
            'lch'   => $this->applyInLchSpace($color, $named, $modify),
            'lab'   => $this->applyInLabSpace($color, $named, $modify, $isLegacy),
            'srgb'  => $this->applyInSrgbSpace($color, $named, $modify),
            default => $this->isGenericColorFunction($color)
                ? $this->applyInGenericColorSpace($color, $named, $modify, $workingSpace)
                : $this->applyInLegacySpace($color, $named, $context),
        };
    }

    /** @param array<string, AstNode> $named */
    private function applyInLegacySpace(AstNode $color, array $named, string $context): AstNode
    {
        $rgb = $this->converter->toRgb($color);

        $values = [
            'red'        => $this->parseNumberChannel($named, 'red', $context),
            'green'      => $this->parseNumberChannel($named, 'green', $context),
            'blue'       => $this->parseNumberChannel($named, 'blue', $context),
            'alpha'      => $this->parseNumberChannel($named, 'alpha', $context),
            'hue'        => $this->parseNumberChannel($named, 'hue', $context),
            'saturation' => $this->parsePercentageChannel($named, 'saturation', $context),
            'lightness'  => $this->parsePercentageChannel($named, 'lightness', $context),
        ];

        if ($context === 'change-color') {
            $hasHslChannel = $values['hue'] !== null
                || $values['saturation'] !== null
                || $values['lightness'] !== null;

            if (! $hasHslChannel) {
                $newR = $values['red'] ?? $rgb->r;
                $newG = $values['green'] ?? $rgb->g;
                $newB = $values['blue'] ?? $rgb->b;
                $newA = $values['alpha'] !== null
                    ? $this->runtime->argumentParser->clamp($values['alpha'], 1.0)
                    : $rgb->a;

                return $this->converter->serializeRgbResult(new RgbColor(r: $newR, g: $newG, b: $newB, a: $newA));
            }
        }

        $hsl = $this->runtime->modelConverter->rgbToHslColor($rgb);

        $modifiedRgb = $context === 'change-color'
            ? $this->legacy->change($rgb, $hsl, $values)
            : $this->legacy->adjust($rgb, $hsl, $values);

        return $this->converter->serializeRgbResult($modifiedRgb);
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInOklchSpace(AstNode $color, array $named, callable $modify): AstNode
    {
        if ($this->converter->isNativeSpace($color, 'oklch')) {
            /** @var FunctionNode $color */
            return $this->applyInNativeOklch($color, $named, $modify);
        }

        $baseColor = $this->converter->createBaseOklchColor($color);

        $values = [
            'lightness' => $this->parsePercentageChannel($named, 'lightness', 'color'),
            'chroma'    => $this->parseNumberChannel($named, 'chroma', 'color'),
            'hue'       => $this->parseNumberChannel($named, 'hue', 'color'),
            'alpha'     => $this->parseNumberChannel($named, 'alpha', 'color'),
        ];

        $newOklchInner = $this->isDirectChange($modify)
            ? $this->perceptual->changeOklch($baseColor, $values)
            : $this->perceptual->adjustOklch($baseColor, $values);

        $unclampedRgb = $this->runtime->spaceConverter->oklchToRgb($newOklchInner);

        $r = $unclampedRgb->r ?? 0.0;
        $g = $unclampedRgb->g ?? 0.0;
        $b = $unclampedRgb->b ?? 0.0;

        if ($r >= 0.0 && $r <= 1.0 && $g >= 0.0 && $g <= 1.0 && $b >= 0.0 && $b <= 1.0) {
            return $this->converter->serializeAsFloatRgb($this->runtime->spaceConverter->oklchToRgb($newOklchInner));
        }

        return $this->converter->serializeAsUnclampedHsl($r, $g, $b, $unclampedRgb->a);
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInNativeOklch(FunctionNode $color, array $named, callable $modify): AstNode
    {
        $baseChannels   = $this->converter->readNativeOklchChannels($color);
        $isDirectChange = $this->isDirectChange($modify);

        [$newL, $lProvided] = $this->parseOklchChannelValue($named, 'lightness', 'percentage');
        [$newC, $cProvided] = $this->parseOklchChannelValue($named, 'chroma', 'chroma');
        [$newH, $hProvided] = $this->parseOklchChannelValue($named, 'hue', 'number');
        [$newA, $aProvided] = $this->parseOklchChannelValue($named, 'alpha', 'number');

        $resultL = $lProvided
            ? ($isDirectChange ? $newL : $this->runtime->spaceConverter->clamp($modify($baseChannels['l'] ?? 0.0, $newL ?? 0.0), 100.0))
            : $baseChannels['l'];
        $resultC = $cProvided
            ? ($isDirectChange ? $newC : $modify($baseChannels['c'] ?? 0.0, $newC ?? 0.0))
            : $baseChannels['c'];
        $resultH = $hProvided
            ? ($isDirectChange ? $newH : $this->runtime->spaceConverter->normalizeHue($modify($baseChannels['h'] ?? 0.0, $newH ?? 0.0)))
            : $baseChannels['h'];
        $resultA = $aProvided
            ? $this->runtime->spaceConverter->clamp($isDirectChange ? ($newA ?? 1.0) : $modify($baseChannels['a'], $newA ?? 0.0), 1.0)
            : $baseChannels['a'];

        if ($resultC !== null && $resultC < 0.0) {
            $resultC = abs($resultC);

            if ($resultH !== null) {
                $resultH = $this->runtime->spaceConverter->normalizeHue($resultH + 180.0);
            }
        }

        return $this->converter->buildOklchColorNodeWithNone([
            'l' => $resultL,
            'c' => $resultC,
            'h' => $resultH,
            'a' => $resultA,
        ]);
    }

    /**
     * @param array<string, AstNode> $named
     * @return array{0: ?float, 1: bool} [value, wasProvided]
     */
    private function parseOklchChannelValue(array $named, string $channel, string $type): array
    {
        if (! array_key_exists($channel, $named)) {
            return [null, false];
        }

        $node = $named[$channel];

        if (AstValueInspector::isNoneKeyword($node)) {
            return [null, true];
        }

        if ($type === 'percentage') {
            $value = $this->runtime->argumentParser->asPercentage($node, 'color');

            if ($node instanceof NumberNode && $node->unit === null) {
                return [$value * 100.0, true];
            }

            return [$value, true];
        }

        if ($type === 'lch-percentage') {
            $value = $this->runtime->argumentParser->asPercentage($node, 'color');

            return [$value, true];
        }

        if ($type === 'chroma') {
            $value = $this->runtime->argumentParser->asNumber($node, 'color');

            if ($node instanceof NumberNode && $node->unit === '%') {
                return [$value / 100.0 * 0.4, true];
            }

            return [$value, true];
        }

        if ($type === 'lch-chroma') {
            $value = $this->runtime->argumentParser->asNumber($node, 'color');

            if ($node instanceof NumberNode && $node->unit === '%') {
                return [$value / 100.0 * 150.0, true];
            }

            return [$value, true];
        }

        if ($type === 'oklab-ab') {
            $value = $this->runtime->argumentParser->asNumber($node, 'color');

            if ($node instanceof NumberNode && $node->unit === '%') {
                return [$value / 100.0 * 0.4, true];
            }

            return [$value, true];
        }

        if ($type === 'lab-ab') {
            $value = $this->runtime->argumentParser->asNumber($node, 'color');

            if ($node instanceof NumberNode && $node->unit === '%') {
                return [$value / 100.0 * 125.0, true];
            }

            return [$value, true];
        }

        return [$this->runtime->argumentParser->asNumber($node, 'color'), true];
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInOklabSpace(AstNode $color, array $named, callable $modify): AstNode
    {
        if (! $this->converter->isNativeSpace($color, 'oklab')) {
            $context = 'color.change';

            return $this->applyInLegacySpace($color, $named, $context);
        }

        /** @var FunctionNode $color */
        [$channels, $alpha] = $this->converter->extractRawChannelsPublic($color);

        $baseL = $this->converter->isMissingPublic($channels[0] ?? null)
            ? null
            : $this->runtime->argumentParser->asPercentage($channels[0], 'color');
        $baseA = $this->converter->isMissingPublic($channels[1] ?? null)
            ? null
            : $this->runtime->argumentParser->asNumber($channels[1], 'color');
        $baseB = $this->converter->isMissingPublic($channels[2] ?? null)
            ? null
            : $this->runtime->argumentParser->asNumber($channels[2], 'color');
        $baseAlpha = $this->converter->parseAlphaPublic($alpha, 'color');

        [$newL, $lProvided]         = $this->parseOklchChannelValue($named, 'lightness', 'percentage');
        [$newA, $aProvided]         = $this->parseOklchChannelValue($named, 'a', 'oklab-ab');
        [$newB, $bProvided]         = $this->parseOklchChannelValue($named, 'b', 'oklab-ab');
        [$newAlpha, $alphaProvided] = $this->parseOklchChannelValue($named, 'alpha', 'number');

        $isDirectChange = $this->isDirectChange($modify);

        $resultL = $lProvided
            ? ($isDirectChange ? $newL : $this->runtime->spaceConverter->clamp($modify($baseL ?? 0.0, $newL ?? 0.0), 100.0))
            : $baseL;
        $resultA = $aProvided
            ? ($isDirectChange ? $newA : $modify($baseA ?? 0.0, $newA ?? 0.0))
            : $baseA;
        $resultB = $bProvided
            ? ($isDirectChange ? $newB : $modify($baseB ?? 0.0, $newB ?? 0.0))
            : $baseB;
        $resultAlpha = $alphaProvided
            ? $this->runtime->spaceConverter->clamp($isDirectChange ? ($newAlpha ?? 1.0) : $modify($baseAlpha, $newAlpha ?? 0.0), 1.0)
            : $baseAlpha;

        return $this->converter->buildOklabColorNodeWithNone([
            'l'     => $resultL,
            'a'     => $resultA,
            'b'     => $resultB,
            'alpha' => $resultAlpha,
        ]);
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInLchSpace(AstNode $color, array $named, callable $modify): AstNode
    {
        if (! $this->converter->isNativeSpace($color, 'lch')) {
            $context = 'color.change';

            return $this->applyInLegacySpace($color, $named, $context);
        }

        /** @var FunctionNode $color */
        [$channels, $alpha] = $this->converter->extractRawChannelsPublic($color);

        $baseL = $this->converter->isMissingPublic($channels[0] ?? null)
            ? null
            : $this->runtime->argumentParser->asPercentage($channels[0], 'color');
        $baseC = $this->converter->isMissingPublic($channels[1] ?? null)
            ? null
            : $this->runtime->argumentParser->asNumber($channels[1], 'color');
        $baseH = $this->converter->isMissingPublic($channels[2] ?? null)
            ? null
            : $this->runtime->argumentParser->asHueAngle($channels[2], 'color');
        $baseAlpha = $this->converter->parseAlphaPublic($alpha, 'color');

        [$newL, $lProvided]         = $this->parseOklchChannelValue($named, 'lightness', 'lch-percentage');
        [$newC, $cProvided]         = $this->parseOklchChannelValue($named, 'chroma', 'lch-chroma');
        [$newH, $hProvided]         = $this->parseOklchChannelValue($named, 'hue', 'number');
        [$newAlpha, $alphaProvided] = $this->parseOklchChannelValue($named, 'alpha', 'number');

        $isDirectChange = $this->isDirectChange($modify);

        $resultL = $lProvided
            ? ($isDirectChange ? $newL : $this->runtime->spaceConverter->clamp($modify($baseL ?? 0.0, $newL ?? 0.0), 100.0))
            : $baseL;
        $resultC = $cProvided
            ? ($isDirectChange ? $newC : $modify($baseC ?? 0.0, $newC ?? 0.0))
            : $baseC;
        $resultH = $hProvided
            ? ($isDirectChange ? $newH : $this->runtime->spaceConverter->normalizeHue($modify($baseH ?? 0.0, $newH ?? 0.0)))
            : $baseH;
        $resultAlpha = $alphaProvided
            ? $this->runtime->spaceConverter->clamp($isDirectChange ? ($newAlpha ?? 1.0) : $modify($baseAlpha, $newAlpha ?? 0.0), 1.0)
            : $baseAlpha;

        if ($resultC !== null && $resultC < 0.0) {
            $resultC = abs($resultC);

            if ($resultH !== null) {
                $resultH = $this->runtime->spaceConverter->normalizeHue($resultH + 180.0);
            }
        }

        return $this->converter->buildLchColorNodeWithNone($resultL, $resultC, $resultH, $resultAlpha);
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInLabSpace(AstNode $color, array $named, callable $modify, bool $isLegacy): AstNode
    {
        if ($this->converter->isNativeSpace($color, 'lab')) {
            return $this->applyInNativeLab($color, $named, $modify);
        }

        $values = $this->buildLabChannelValues($named);

        $lab = $this->runtime->spaceConverter->xyzD50ToLab(
            $this->converter->toXyzD50($color),
            $this->converter->toAlpha($color),
        );

        $newLabInner = $this->isDirectChange($modify)
            ? $this->perceptual->changeLab($lab, $values)
            : $this->perceptual->adjustLab($lab, $values);

        $newRgb = $this->converter->convertLabToRgb($newLabInner);

        return $isLegacy ? $this->converter->fromRgb($newRgb) : $this->serializeFloatRgbFromByteRgb($newRgb);
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInNativeLab(AstNode $color, array $named, callable $modify): AstNode
    {
        /** @var FunctionNode $color */
        [$channels, $alpha] = $this->converter->extractRawChannelsPublic($color);

        $baseL = $this->converter->isMissingPublic($channels[0] ?? null)
            ? null
            : $this->runtime->argumentParser->asPercentage($channels[0], 'color');
        $baseA = $this->converter->isMissingPublic($channels[1] ?? null)
            ? null
            : $this->runtime->argumentParser->asNumber($channels[1], 'color');
        $baseB = $this->converter->isMissingPublic($channels[2] ?? null)
            ? null
            : $this->runtime->argumentParser->asNumber($channels[2], 'color');
        $baseAlpha = $this->converter->parseAlphaPublic($alpha, 'color');

        [$newL, $lProvided]         = $this->parseOklchChannelValue($named, 'lightness', 'lch-percentage');
        [$newA, $aProvided]         = $this->parseOklchChannelValue($named, 'a', 'lab-ab');
        [$newB, $bProvided]         = $this->parseOklchChannelValue($named, 'b', 'lab-ab');
        [$newAlpha, $alphaProvided] = $this->parseOklchChannelValue($named, 'alpha', 'number');

        $isDirectChange = $this->isDirectChange($modify);

        $resultL = $lProvided
            ? ($isDirectChange ? $newL : $this->runtime->spaceConverter->clamp($modify($baseL ?? 0.0, $newL ?? 0.0), 100.0))
            : $baseL;
        $resultA = $aProvided
            ? ($isDirectChange ? $newA : $modify($baseA ?? 0.0, $newA ?? 0.0))
            : $baseA;
        $resultB = $bProvided
            ? ($isDirectChange ? $newB : $modify($baseB ?? 0.0, $newB ?? 0.0))
            : $baseB;
        $resultAlpha = $alphaProvided
            ? $this->runtime->spaceConverter->clamp($isDirectChange ? ($newAlpha ?? 1.0) : $modify($baseAlpha, $newAlpha ?? 0.0), 1.0)
            : $baseAlpha;

        return $this->converter->buildLabColorNodeWithNone([
            'l'     => $resultL,
            'a'     => $resultA,
            'b'     => $resultB,
            'alpha' => $resultAlpha,
        ]);
    }

    /**
     * @param callable(array{h: float, s: float, l: float, a: float}): array{h: float, s: float, l: float, a: float} $modify
     */
    private function emitModifiedLegacyColor(AstNode $color, callable $modify): AstNode
    {
        $legacyHsl = $this->extractLegacyHsl($color) ?? [
            'channels' => $this->legacyMath->rgbToHsl($this->converter->toRgb($color)),
            'origin'   => 'rgb',
        ];

        $modified = $modify($legacyHsl['channels']);

        if ($legacyHsl['origin'] === 'rgb') {
            return $this->converter->serializeRgbResult(
                $this->legacyMath->hslToRgb($modified['h'], $modified['s'], $modified['l'], $modified['a']),
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

    /**
     * @param array<string, AstNode> $named
     * @return array<string, float|null>
     */
    private function buildLabChannelValues(array $named): array
    {
        return [
            'lightness' => $this->parsePercentageChannel($named, 'lightness', 'color'),
            'a'         => $this->parseNumberChannel($named, 'a', 'color'),
            'b'         => $this->parseNumberChannel($named, 'b', 'color'),
            'alpha'     => $this->parseNumberChannel($named, 'alpha', 'color'),
        ];
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInSrgbSpace(AstNode $color, array $named, callable $modify): AstNode
    {
        [$r, $g, $b] = $this->extractSrgbChannels($color);

        $alpha = $this->converter->toAlpha($color);

        $values = [
            'red'   => $this->parseNumberChannel($named, 'red', 'color'),
            'green' => $this->parseNumberChannel($named, 'green', 'color'),
            'blue'  => $this->parseNumberChannel($named, 'blue', 'color'),
        ];

        [$newR, $newG, $newB] = $this->isDirectChange($modify)
            ? $this->srgb->change($r, $g, $b, $values)
            : $this->srgb->adjust($r, $g, $b, $values);

        $alphaValue = $this->parseColorChannel($named, 'alpha');

        if ($alphaValue !== null) {
            $alpha = $modify($alpha, $alphaValue);
        }

        return $this->converter->serializeAsSrgbString($newR, $newG, $newB, $alpha);
    }

    private function isGenericColorFunction(AstNode $color): bool
    {
        return $color instanceof FunctionNode
            && strtolower($color->name) === 'color';
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInGenericColorSpace(AstNode $color, array $named, callable $modify, string $space): AstNode
    {
        [$r, $g, $b] = $this->extractSrgbChannels($color);

        $alpha = $this->converter->toAlpha($color);

        $redAmount   = $this->parseColorChannel($named, 'red');
        $greenAmount = $this->parseColorChannel($named, 'green');
        $blueAmount  = $this->parseColorChannel($named, 'blue');

        if ($redAmount !== null) {
            $r = $modify($r, $redAmount);
        }

        if ($greenAmount !== null) {
            $g = $modify($g, $greenAmount);
        }

        if ($blueAmount !== null) {
            $b = $modify($b, $blueAmount);
        }

        $alphaValue = $this->parseColorChannel($named, 'alpha');

        if ($alphaValue !== null) {
            $alpha = $modify($alpha, $alphaValue);
        }

        return $this->converter->buildGenericColorFunctionNode($space, [$r, $g, $b], $alpha);
    }

    /** @param array<string, AstNode> $named */
    private function scaleInGenericColorSpace(AstNode $color, array $named): AstNode
    {
        if (! ($color instanceof FunctionNode)) {
            return $color;
        }

        $space = $this->converter->detectGenericColorSpace($color);

        [$r, $g, $b] = $this->extractSrgbChannels($color);

        $alpha = $this->converter->toAlpha($color);

        $r     = $this->applyScale($r, $this->parseScalePercentage($named, 'red'));
        $g     = $this->applyScale($g, $this->parseScalePercentage($named, 'green'));
        $b     = $this->applyScale($b, $this->parseScalePercentage($named, 'blue'));
        $alpha = $this->applyScale($alpha, $this->parseScalePercentage($named, 'alpha'));

        return $this->converter->buildGenericColorFunctionNode($space, [$r, $g, $b], $alpha);
    }

    private function applyScale(float $current, ?float $percentage): float
    {
        if ($percentage === null) {
            return $current;
        }

        $fraction = $percentage / 100.0;

        if ($fraction >= 0.0) {
            return $current + (1.0 - $current) * $fraction;
        }

        return $current + $current * $fraction;
    }

    /** @param array<string, AstNode> $named */
    private function parseColorChannel(array $named, string $channel): ?float
    {
        if (! array_key_exists($channel, $named)) {
            return null;
        }

        $node = $named[$channel];

        if (! ($node instanceof NumberNode)) {
            return null;
        }

        $value = (float) $node->value;

        if ($node->unit === '%') {
            return $value / 100.0;
        }

        return $value;
    }

    /** @param array<string, AstNode> $named */
    private function parseScalePercentage(array $named, string $channel): ?float
    {
        if (! array_key_exists($channel, $named)) {
            return null;
        }

        return $this->runtime->argumentParser->asPercentage($named[$channel], 'scale-color');
    }

    /** @param array<string, AstNode> $named */
    private function parseNumberChannel(array $named, string $channel, string $context): ?float
    {
        if (! array_key_exists($channel, $named)) {
            return null;
        }

        return $this->runtime->argumentParser->asNumber($named[$channel], $context);
    }

    /** @param array<string, AstNode> $named */
    private function parsePercentageChannel(array $named, string $channel, string $context): ?float
    {
        if (! array_key_exists($channel, $named)) {
            return null;
        }

        return $this->runtime->argumentParser->asPercentage($named[$channel], $context);
    }

    private function createOklchFromRgb(RgbColor $rgb): OklchColor
    {
        return $this->converter->createOklchFromRgb($rgb);
    }

    private function serializeFloatRgbFromByteRgb(RgbColor $rgb): AstNode
    {
        return $this->converter->serializeAsFloatRgb(
            new RgbColor(
                r: $rgb->rValue() / 255.0,
                g: $rgb->gValue() / 255.0,
                b: $rgb->bValue() / 255.0,
                a: $rgb->a,
            ),
        );
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function extractSrgbChannels(AstNode $color): array
    {
        return $this->converter->extractSrgbChannels($color);
    }

    /** @param callable(float, float): float $modify */
    private function isDirectChange(callable $modify): bool
    {
        return $modify(1.0, 2.0) === 2.0;
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

    /** @return array{value: float, missing: bool} */
    private function mixPossiblyMissingChannel(
        float $left,
        float $right,
        bool $leftMissing,
        bool $rightMissing,
        float $p,
    ): array {
        if ($leftMissing && $rightMissing) {
            return ['value' => 0.0, 'missing' => true];
        }

        if ($leftMissing) {
            return ['value' => $right, 'missing' => false];
        }

        if ($rightMissing) {
            return ['value' => $left, 'missing' => false];
        }

        return ['value' => $this->runtime->spaceConverter->mixChannel($left, $right, $p), 'missing' => false];
    }

    /** @return array{value: float, missing: bool} */
    private function mixPossiblyMissingHue(
        float $left,
        float $right,
        bool $leftMissing,
        bool $rightMissing,
        float $p,
        ?string $method,
    ): array {
        if ($leftMissing && $rightMissing) {
            return ['value' => 0.0, 'missing' => true];
        }

        if ($leftMissing) {
            return ['value' => $right, 'missing' => false];
        }

        if ($rightMissing) {
            return ['value' => $left, 'missing' => false];
        }

        return ['value' => $this->interpolateHue($left, $right, $p, $method), 'missing' => false];
    }

    private function mixColorSpaceChannels(AstNode $color1, AstNode $color2, float $p): AstNode
    {
        $channels1 = $this->extractColorSpaceChannels($color1);
        $channels2 = $this->extractColorSpaceChannels($color2);
        $mixed     = [];

        foreach ([0, 1, 2] as $i) {
            $left  = $channels1[$i];
            $right = $channels2[$i];

            if ($left === null && $right === null) {
                $mixed[] = 'none';

                continue;
            }

            if ($left === null) {
                $mixed[] = $this->runtime->spaceConverter->trimFloat($right, 10);

                continue;
            }

            if ($right === null) {
                $mixed[] = $this->runtime->spaceConverter->trimFloat($left, 10);

                continue;
            }

            $mixed[] = $this->runtime->spaceConverter->trimFloat(
                $this->runtime->spaceConverter->mixChannel($left, $right, $p),
                10,
            );
        }

        return $this->converter->buildFunctionalColorNode('color', [
            new StringNode('rec2020'),
            $mixed[0] === 'none' ? new StringNode('none') : new NumberNode((float) $mixed[0]),
            $mixed[1] === 'none' ? new StringNode('none') : new NumberNode((float) $mixed[1]),
            $mixed[2] === 'none' ? new StringNode('none') : new NumberNode((float) $mixed[2]),
        ], 1.0);
    }

    /** @return array{0: ?float, 1: ?float, 2: ?float} */
    private function extractColorSpaceChannels(AstNode $color): array
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'color') {
            $channels = $this->converter->extractChannelNodes($color);

            if (
                isset($channels[0])
                && $channels[0] instanceof StringNode
                && strtolower($channels[0]->value) === 'rec2020'
            ) {
                return [
                    $this->extractOptionalNumericChannel($channels[1] ?? null),
                    $this->extractOptionalNumericChannel($channels[2] ?? null),
                    $this->extractOptionalNumericChannel($channels[3] ?? null),
                ];
            }
        }

        /** @var array{0: float, 1: float, 2: float} $channels */
        $channels = $this->runtime->spaceConverter->rgbToRec2020Channels($this->converter->toRgb($color));

        return [$channels[0], $channels[1], $channels[2]];
    }

    private function extractOptionalNumericChannel(?AstNode $node): ?float
    {
        if ($node === null || $this->runtime->argumentParser->isMissingChannelNode($node)) {
            return null;
        }

        if ($node instanceof NumberNode && $node->unit === '%') {
            return $this->runtime->argumentParser->clamp((float) $node->value / 100.0, 1.0);
        }

        return $this->runtime->argumentParser->clamp(
            $this->runtime->argumentParser->asNumber($node, 'mix'),
            1.0,
        );
    }

    private function interpolateHue(float $h1, float $h2, float $p, ?string $method = null): float
    {
        return $this->mixResolver->mixOklch(
            new OklchColor(0.0, 0.0, $h1),
            new OklchColor(0.0, 0.0, $h2),
            $p,
            $method ?? 'shorter',
        )->hValue();
    }

    private function mixInHslSpace(AstNode $color1, AstNode $color2, float $p, ?string $hueMethod): ?AstNode
    {
        $hsl1 = $this->spaceInterop->toHslWithMissingChannels($color1);
        $hsl2 = $this->spaceInterop->toHslWithMissingChannels($color2);

        if ($hsl1 === null || $hsl2 === null) {
            return null;
        }

        $h1 = $hsl1->h;
        $h2 = $hsl2->h;

        if ($hsl1->h === null && $hsl2->h !== null) {
            $h1 = $hsl2->h;
        } elseif ($hsl2->h === null && $hsl1->h !== null) {
            $h2 = $hsl1->h;
        }

        $mixedHsl = $this->mixResolver->mixHsl(
            new HslColor($h1, $hsl1->s, $hsl1->l, $hsl1->a),
            new HslColor($h2, $hsl2->s, $hsl2->l, $hsl2->a),
            $p,
            $hueMethod ?? 'shorter',
        );

        $hue = $mixedHsl->hValue();
        $sat = $this->runtime->argumentParser->clamp(
            $this->runtime->spaceConverter->mixChannel($hsl1->s, $hsl2->s, $p),
            100.0,
        );
        $lig = $this->runtime->argumentParser->clamp(
            $this->runtime->spaceConverter->mixChannel($hsl1->l, $hsl2->l, $p),
            100.0,
        );
        $alp = $mixedHsl->a;

        return $this->converter->buildHslFunctionNode($hue, $sat, $lig, $alp);
    }

    private function mixInOklchSpace(AstNode $color1, AstNode $color2, float $p, ?string $hueMethod): AstNode
    {
        $oklch1 = $this->spaceInterop->extractOklchMixData($color1);
        $oklch2 = $this->spaceInterop->extractOklchMixData($color2);

        $lightness = $this->mixPossiblyMissingChannel(
            $oklch1['l'],
            $oklch2['l'],
            $oklch1['l_missing'],
            $oklch2['l_missing'],
            $p,
        );

        $chroma = $this->mixPossiblyMissingChannel(
            $oklch1['c'],
            $oklch2['c'],
            $oklch1['c_missing'],
            $oklch2['c_missing'],
            $p,
        );

        $hue = $this->mixPossiblyMissingHue(
            $oklch1['h'],
            $oklch2['h'],
            $oklch1['h_missing'],
            $oklch2['h_missing'],
            $p,
            $hueMethod,
        );

        $mix = new OklchColor(
            l: $lightness['value'],
            c: $chroma['value'],
            h: $hue['value'],
            a: $this->runtime->spaceConverter->mixChannel($oklch1['a'], $oklch2['a'], $p),
        );

        if (
            $this->converter->detectNativeColorSpace($color1) === 'oklch'
            && $this->converter->detectNativeColorSpace($color2) === 'oklch'
        ) {
            return $this->converter->buildFunctionalColorNode('oklch', [
                $lightness['missing'] ? new StringNode('none') : new NumberNode($mix->lValue(), '%'),
                $chroma['missing'] ? new StringNode('none') : new NumberNode($mix->cValue()),
                $hue['missing'] ? new StringNode('none') : new NumberNode($mix->hValue(), 'deg'),
            ], $mix->a);
        }

        return $this->converter->serializeLegacyRgbFunction($this->runtime->spaceConverter->oklchToRgb($mix));
    }
}
