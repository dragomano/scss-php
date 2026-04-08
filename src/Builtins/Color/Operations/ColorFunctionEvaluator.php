<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorManipulators;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;

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
        private ColorManipulators $manipulators,
        private ColorNodeConverter $converter,
        private ColorSpaceConverter $spaceInterop,
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
        $color       = $this->runtime->argumentParser->requireColor($positional, 0, 'scale-color');
        $spaceNode   = $named['space'] ?? null;
        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (
            ($spaceNode instanceof StringNode && strtolower($spaceNode->value) === 'oklch')
            || $nativeSpace === 'oklch'
        ) {
            return $this->scaleColorInOklch($color, $named);
        }

        $rgb = $this->converter->toRgb($color);

        $scaledRgb = $this->manipulators->legacy->scale($rgb, $this->runtime->modelConverter->rgbToHslColor($rgb), [
            'red'        => $this->parseScalePercentage($named, 'red'),
            'green'      => $this->parseScalePercentage($named, 'green'),
            'blue'       => $this->parseScalePercentage($named, 'blue'),
            'alpha'      => $this->parseScalePercentage($named, 'alpha'),
            'saturation' => $this->parseScalePercentage($named, 'saturation'),
            'lightness'  => $this->parseScalePercentage($named, 'lightness'),
        ]);

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

        return $this->converter->serializeAsFloatRgb($this->runtime->spaceConverter->oklchToSrgb($scaled));
    }

    /** @param array<int, AstNode> $positional */
    public function adjustHue(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $color   = $this->runtime->argumentParser->requireColor($positional, 0, 'adjust-hue');
        $degrees = $this->runtime->argumentParser->asNumber($positional[1] ?? null, 'adjust-hue');

        $this->runtime->context->warn(
            $context,
            $this->formatColorAdjustHint($color, 'hue', $this->runtime->formatter->formatDegrees($degrees)),
        );

        if ($this->converter->isLegacyColor($color)) {
            $adjustedRgb = $this->manipulators->legacy->spin($this->converter->toRgb($color), $degrees);

            return $this->converter->serializeRgbResult($adjustedRgb);
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

        $this->runtime->context->warn(
            $callContext,
            $this->buildScaleSuggestion($color, 'alpha', $direction, $amount) . ', or '
            . $this->formatColorAdjustHint($color, 'alpha', $this->runtime->formatter->formatSignedNumber($amount)),
            true,
        );

        if ($this->converter->isLegacyColor($color)) {
            $rgb = $this->converter->toRgb($color);

            $adjustedRgb = $direction > 0
                ? $this->manipulators->legacy->fadeIn($rgb, $amount)
                : $this->manipulators->legacy->fadeOut($rgb, -$amount);

            return $this->converter->serializeRgbResult($adjustedRgb);
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

        $this->runtime->context->warn(
            $callContext,
            $this->buildScaleSuggestion($color, $channel, $direction, $amount) . ', or '
            . $this->formatColorAdjustHint($color, $channel, $this->runtime->formatter->formatSignedPercentage($amount)),
            true,
        );

        if ($this->converter->isLegacyColor($color)) {
            $rgb = $this->converter->toRgb($color);

            $modified = match ($channel) {
                'lightness'  => $direction > 0
                    ? $this->manipulators->legacy->lighten($rgb, $amount)
                    : $this->manipulators->legacy->darken($rgb, -$amount),
                'saturation' => $direction > 0
                    ? $this->manipulators->legacy->saturate($rgb, $amount)
                    : $this->manipulators->legacy->desaturate($rgb, -$amount),
                default      => null,
            };

            if ($modified !== null) {
                return $this->converter->serializeRgbResult($modified);
            }
        }

        return $this->adjustColor([$color], [$channel => new NumberNode($amount, '%')], $context);
    }

    /** @param array<int, AstNode> $positional */
    public function complement(array $positional): AstNode
    {
        $color = $this->runtime->argumentParser->requireColor($positional, 0, 'complement');
        $space = isset($positional[1])
            ? strtolower($this->runtime->argumentParser->asString($positional[1], 'complement'))
            : null;

        $named = ['hue' => new NumberNode(180)];

        if ($space !== null) {
            $named['space'] = new StringNode($space);
        }

        if ($space === null && $this->converter->isLegacyColor($color)) {
            $adjustedRgb = $this->manipulators->legacy->spin($this->converter->toRgb($color), 180.0);

            return $this->converter->serializeRgbResult($adjustedRgb);
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
            $hsl     = $this->converter->toHsl($color);
            $grayRgb = $this->runtime->modelConverter->hslToRgbColor($this->manipulators->legacy->grayscale($hsl));

            return $this->converter->serializeRgbResult($grayRgb);
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
        $grayRgb   = $this->runtime->spaceConverter->oklchToSrgb($grayOklch);

        if ($nativeSpace === 'srgb') {
            return $this->converter->serializeAsSrgbString($grayRgb->rValue(), $grayRgb->gValue(), $grayRgb->bValue());
        }

        return $this->converter->serializeAsFloatRgb($grayRgb);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function mix(array $positional, array $named): AstNode
    {
        $color1     = $this->runtime->argumentParser->requireColor($positional, 0, 'mix');
        $color2     = $this->runtime->argumentParser->requireColor($positional, 1, 'mix');
        $methodNode = $named['method'] ?? ($positional[3] ?? null);
        $weight     = $this->runtime->argumentParser->asPercentage(
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

        $mixedRgbInner = $this->manipulators->legacy->mix($rgb1, $rgb2, $p);

        if ($methodNode instanceof StringNode || $methodNode instanceof ListNode) {
            return $this->converter->serializeRgbResult($mixedRgbInner);
        }

        return $this->converter->fromRgb($mixedRgbInner);
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

        $invertedRgb = $this->manipulators->legacy->invert($rgb, $p);

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

        $color        = $this->runtime->argumentParser->requireColor($positional, 0, $context);
        $isLegacy     = $this->converter->isLegacyColor($color);
        $nativeSpace  = $this->converter->detectNativeColorSpace($color);
        $workingSpace = $requestedSpace ?? $nativeSpace;

        return match ($workingSpace) {
            'oklch' => $this->applyInOklchSpace($color, $named, $modify),
            'lab'   => $this->applyInLabSpace($color, $named, $modify, $isLegacy),
            'srgb'  => $this->applyInSrgbSpace($color, $named, $modify),
            default => $this->applyInLegacySpace($color, $named, $context),
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

        $hsl = $this->runtime->modelConverter->rgbToHslColor($rgb);

        $modifiedRgb = $context === 'change-color'
            ? $this->manipulators->legacy->change($rgb, $hsl, $values)
            : $this->manipulators->legacy->adjust($rgb, $hsl, $values);

        return $this->converter->serializeRgbResult($modifiedRgb);
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInOklchSpace(AstNode $color, array $named, callable $modify): AstNode
    {
        $isNativeOklch = $this->converter->isNativeSpace($color, 'oklch');
        $baseColor     = $this->converter->createBaseOklchColor($color);

        $values = [
            'lightness' => $this->parsePercentageChannel($named, 'lightness', 'color'),
            'chroma'    => $this->parseNumberChannel($named, 'chroma', 'color'),
            'hue'       => $this->parseNumberChannel($named, 'hue', 'color'),
            'alpha'     => $this->parseNumberChannel($named, 'alpha', 'color'),
        ];

        $newOklchInner = $this->isDirectChange($modify)
            ? $this->manipulators->perceptual->changeOklch($baseColor, $values)
            : $this->manipulators->perceptual->adjustOklch($baseColor, $values);

        if ($isNativeOklch) {
            return $this->converter->serializeAsOklchString($newOklchInner);
        }

        return $this->converter->serializeAsFloatRgb($this->runtime->spaceConverter->oklchToSrgb($newOklchInner));
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInLabSpace(AstNode $color, array $named, callable $modify, bool $isLegacy): AstNode
    {
        $values = $this->buildLabChannelValues($named);

        if ($this->converter->isNativeSpace($color, 'lab')) {
            /** @var FunctionNode $color */
            $lab = $this->converter->readNativeLab($color);

            $newLabInner = $this->isDirectChange($modify)
                ? $this->manipulators->perceptual->changeLab($lab, $values)
                : $this->manipulators->perceptual->adjustLab($lab, $values);

            if (! $isLegacy) {
                return $this->converter->buildLabColorNode($newLabInner);
            }

            return $this->serializeLabAsFloatRgb($newLabInner);
        }

        $lab = $this->runtime->spaceConverter->xyzD50ToLabColor(
            $this->converter->toXyzD50($color),
            $this->converter->toAlpha($color),
        );

        $newLabInner = $this->isDirectChange($modify)
            ? $this->manipulators->perceptual->changeLab($lab, $values)
            : $this->manipulators->perceptual->adjustLab($lab, $values);

        $newRgb = $this->converter->convertLabToRgb($newLabInner);

        return $isLegacy ? $this->converter->fromRgb($newRgb) : $this->serializeFloatRgbFromByteRgb($newRgb);
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

        $values = [
            'red'   => $this->parseNumberChannel($named, 'red', 'color'),
            'green' => $this->parseNumberChannel($named, 'green', 'color'),
            'blue'  => $this->parseNumberChannel($named, 'blue', 'color'),
        ];

        [$newR, $newG, $newB] = $this->isDirectChange($modify)
            ? $this->manipulators->srgb->change($r, $g, $b, $values)
            : $this->manipulators->srgb->adjust($r, $g, $b, $values);

        return $this->converter->serializeAsSrgbString($newR, $newG, $newB);
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

    private function serializeLabAsFloatRgb(LabColor $lab): AstNode
    {
        return $this->serializeFloatRgbFromByteRgb($this->converter->convertLabToRgb($lab));
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
        $channels = $this->runtime->spaceConverter->rgbToRec2020($this->converter->toRgb($color));

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
        return $this->manipulators->mixResolver->mixOklch(
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

        $mixedHsl = $this->manipulators->mixResolver->mixHsl(
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

        return $this->converter->serializeLegacyRgbFunction($this->runtime->spaceConverter->oklchToSrgb($mix));
    }
}
