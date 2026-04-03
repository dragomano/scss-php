<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Operations;

use Bugo\Iris\Operations\ColorMixResolver;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisColorValue;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstReader;
use Bugo\SCSS\Builtins\Color\Ast\ColorAstWriter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\ColorSpaceInterop;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Builtins\Color\Support\ColorValueFormatter;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Contracts\Color\ColorManipulatorInterface;
use Bugo\SCSS\Contracts\Color\ColorValueInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Runtime\BuiltinCallContext;
use Closure;

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
    /**
     * @param Closure(?BuiltinCallContext, string, bool=): void $warn
     */
    public function __construct(
        private ColorArgumentParser $parser,
        private ColorNodeConverter $converter,
        private ColorAstWriter $astWriter,
        private ColorAstReader $astReader,
        private ColorManipulatorInterface $manipulator,
        private ColorSpaceInterop $spaceInterop,
        private ColorValueFormatter $formatter,
        private ColorConverterInterface $colorSpaceConverter,
        private Closure $warn,
        private ColorMixResolver $colorMixResolver = new ColorMixResolver()
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
            fn(float $current, float $delta): float => $current + $delta
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
            fn(float $current, float $value): float => $value
        );
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function scaleColor(array $positional, array $named): AstNode
    {
        $color       = $this->parser->requireColor($positional, 0, 'scale-color');
        $spaceNode   = $named['space'] ?? null;
        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if (
            ($spaceNode instanceof StringNode && strtolower($spaceNode->value) === 'oklch')
            || $nativeSpace === 'oklch'
        ) {
            return $this->scaleColorInOklch($color, $named);
        }

        $rgb = $this->converter->toRgb($color);

        $scaledColor = $this->manipulator->scaleColor(new IrisColorValue($rgb), [
            'red'        => $this->parseScalePercentage($named, 'red'),
            'green'      => $this->parseScalePercentage($named, 'green'),
            'blue'       => $this->parseScalePercentage($named, 'blue'),
            'alpha'      => $this->parseScalePercentage($named, 'alpha'),
            'saturation' => $this->parseScalePercentage($named, 'saturation'),
            'lightness'  => $this->parseScalePercentage($named, 'lightness'),
        ]);

        /** @var ColorValueInterface<RgbColor> $scaledColor */
        $scaledRgb = $scaledColor->getInner();

        return $this->astWriter->serializeRgbResult($scaledRgb);
    }

    /** @param array<string, AstNode> $named */
    public function scaleColorInOklch(AstNode $color, array $named): AstNode
    {
        $oklch = $this->extractNativeOrConvertedOklchColor($color);

        $scaled = new OklchColor(
            l: $this->colorSpaceConverter->scaleLinear(
                $oklch->lValue(),
                $this->parseScalePercentage($named, 'lightness') ?? 0.0,
                100.0
            ),
            c: $this->colorSpaceConverter->scaleLinear(
                $oklch->cValue(),
                $this->parseScalePercentage($named, 'chroma') ?? 0.0,
                0.4
            ),
            h: $oklch->h,
            a: $this->colorSpaceConverter->scaleLinear(
                $oklch->a,
                $this->parseScalePercentage($named, 'alpha') ?? 0.0,
                1.0
            ),
        );

        if ($this->converter->detectNativeColorSpace($color) === 'oklch') {
            return $this->astWriter->serializeAsOklchColorFunction($scaled);
        }

        return $this->astWriter->serializeAsFloatRgb($this->colorSpaceConverter->oklchToSrgb($scaled));
    }

    /** @param array<int, AstNode> $positional */
    public function adjustHue(array $positional, ?BuiltinCallContext $context): AstNode
    {
        $color   = $this->parser->requireColor($positional, 0, 'adjust-hue');
        $degrees = $this->parser->asNumber($positional[1] ?? null, 'adjust-hue');

        ($this->warn)(
            $context,
            $this->formatColorAdjustHint($color, 'hue', $this->formatter->formatDegrees($degrees))
        );

        if ($this->converter->isLegacyColor($color)) {
            $adjustedColor = $this->manipulator->spin(
                new IrisColorValue($this->converter->toRgb($color)),
                $degrees
            );

            /** @var ColorValueInterface<RgbColor> $adjustedColor */
            $adjustedRgb = $adjustedColor->getInner();

            return $this->astWriter->serializeRgbResult($adjustedRgb);
        }

        return $this->adjustColor([$color], ['hue' => new NumberNode($degrees)], 'adjust-hue');
    }

    /** @param array<int, AstNode> $positional */
    public function adjustAlphaChannel(
        array $positional,
        int $direction,
        string $context,
        ?BuiltinCallContext $callContext
    ): AstNode {
        $color  = $this->parser->requireColor($positional, 0, $context);
        $amount = $this->parser->asNumber($positional[1] ?? null, $context) * (float) $direction;

        ($this->warn)(
            $callContext,
            $this->buildScaleSuggestion($color, 'alpha', $direction, $amount) . ', or '
            . $this->formatColorAdjustHint($color, 'alpha', $this->formatter->formatSignedNumber($amount)),
            true
        );

        if ($this->converter->isLegacyColor($color)) {
            $rgb = new IrisColorValue($this->converter->toRgb($color));

            $adjustedColor = $direction > 0
                ? $this->manipulator->fadeIn($rgb, $amount)
                : $this->manipulator->fadeOut($rgb, -$amount);

            /** @var ColorValueInterface<RgbColor> $adjustedColor */
            $adjustedRgb = $adjustedColor->getInner();

            return $this->astWriter->serializeRgbResult($adjustedRgb);
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
        ?BuiltinCallContext $callContext = null
    ): AstNode {
        $color = $allowCssDefer
            ? $this->parser->requireColorOrDefer($positional, $context)
            : $this->parser->requireColor($positional, 0, $context);

        $amount = $this->parser->asPercentage($positional[1] ?? null, $context) * (float) $direction;

        ($this->warn)(
            $callContext,
            $this->buildScaleSuggestion($color, $channel, $direction, $amount) . ', or '
            . $this->formatColorAdjustHint($color, $channel, $this->formatter->formatSignedPercentage($amount)),
            true
        );

        if ($this->converter->isLegacyColor($color)) {
            $rgb = $this->converter->toRgb($color);

            $modified = match ($channel) {
                'lightness'  => $direction > 0
                    ? $this->manipulator->lighten(new IrisColorValue($rgb), $amount)
                    : $this->manipulator->darken(new IrisColorValue($rgb), -$amount),
                'saturation' => $direction > 0
                    ? $this->manipulator->saturate(new IrisColorValue($rgb), $amount)
                    : $this->manipulator->desaturate(new IrisColorValue($rgb), -$amount),
                default      => null,
            };

            if ($modified !== null) {
                /** @var ColorValueInterface<RgbColor> $modified */
                $modifiedRgb = $modified->getInner();

                return $this->astWriter->serializeRgbResult($modifiedRgb);
            }
        }

        return $this->adjustColor([$color], [$channel => new NumberNode($amount, '%')], $context);
    }

    /** @param array<int, AstNode> $positional */
    public function complement(array $positional): AstNode
    {
        $color = $this->parser->requireColor($positional, 0, 'complement');
        $space = isset($positional[1])
            ? strtolower($this->parser->asString($positional[1], 'complement'))
            : null;

        $named = ['hue' => new NumberNode(180)];

        if ($space !== null) {
            $named['space'] = new StringNode($space);
        }

        if ($space === null && $this->converter->isLegacyColor($color)) {
            $adjustedColor = $this->manipulator->spin(
                new IrisColorValue($this->converter->toRgb($color)),
                180.0
            );

            /** @var ColorValueInterface<RgbColor> $adjustedColor */
            $adjustedRgb = $adjustedColor->getInner();

            return $this->astWriter->serializeRgbResult($adjustedRgb);
        }

        return $this->applyColorModification(
            [$color],
            $named,
            'complement',
            fn(float $current, float $delta): float => $current + $delta
        );
    }

    /** @param array<int, AstNode> $positional */
    public function grayscale(array $positional): AstNode
    {
        $color = $this->parser->requireColorOrDefer($positional, 'grayscale');

        if ($this->converter->isLegacyColor($color)) {
            $hsl       = $this->converter->toHsl($color);
            $grayColor = $this->manipulator->hslToRgb($this->manipulator->grayscale(new IrisColorValue($hsl)));

            /** @var ColorValueInterface<RgbColor> $grayColor */
            $grayRgb = $grayColor->getInner();

            return $this->astWriter->serializeRgbResult($grayRgb);
        }

        $nativeSpace = $this->converter->detectNativeColorSpace($color);

        if ($nativeSpace === 'oklch' && $color instanceof FunctionNode) {
            $oklch = $this->astReader->readNativeOklch($color);

            return $this->astWriter->serializeAsOklchString(
                new OklchColor(
                    l: $oklch->l,
                    c: 0.0,
                    h: $oklch->h,
                    a: 1.0
                ),
                true
            );
        }

        $rgb       = $this->converter->toRgb($color);
        $oklch     = $this->createOklchFromRgb($rgb);
        $grayOklch = new OklchColor(l: $oklch->l, c: 0.0, h: $oklch->h, a: $rgb->a);
        $grayRgb   = $this->colorSpaceConverter->oklchToSrgb($grayOklch);

        if ($nativeSpace === 'srgb') {
            return $this->astWriter->serializeAsSrgbString($grayRgb->rValue(), $grayRgb->gValue(), $grayRgb->bValue());
        }

        return $this->astWriter->serializeAsFloatRgb($grayRgb);
    }

    /**
     * @param array<int, AstNode> $positional
     * @param array<string, AstNode> $named
     */
    public function mix(array $positional, array $named): AstNode
    {
        $color1     = $this->parser->requireColor($positional, 0, 'mix');
        $color2     = $this->parser->requireColor($positional, 1, 'mix');
        $methodNode = $named['method'] ?? ($positional[3] ?? null);
        $weight     = $this->parser->asPercentage(
            $named['weight'] ?? ($positional[2] ?? new NumberNode(50)),
            'mix'
        );

        ['space' => $method, 'hue' => $hueMethod] = $this->resolveMixMethod($named, $positional);

        $rgb1 = $this->converter->toRgb($color1);
        $rgb2 = $this->converter->toRgb($color2);
        $p    = $this->parser->clamp($weight / 100.0, 1.0);

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

        $mixedColor = $this->manipulator->mix(new IrisColorValue($rgb1), new IrisColorValue($rgb2), $p);

        /** @var ColorValueInterface<RgbColor> $mixedColor */
        $mixedRgbInner = $mixedColor->getInner();

        if ($methodNode instanceof StringNode || $methodNode instanceof ListNode) {
            return $this->astWriter->serializeRgbResult($mixedRgbInner);
        }

        return $this->astWriter->fromRgb($mixedRgbInner);
    }

    /** @param array<int, AstNode> $positional */
    public function same(array $positional): BooleanNode
    {
        $left  = $this->converter->toRgb($this->parser->requireColor($positional, 0, 'same'));
        $right = $this->converter->toRgb($this->parser->requireColor($positional, 1, 'same'));

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
        $color = $this->parser->requireColorOrDefer($positional, 'invert');

        $space = strtolower(
            $this->parser->asString(
                $named['space'] ?? new StringNode('rgb'),
                'invert'
            )
        );

        $weight = $this->parser->asPercentage(
            $named['weight'] ?? ($positional[1] ?? new NumberNode(100)),
            'invert'
        );

        $p   = $this->parser->clamp($weight / 100.0, 1.0);
        $rgb = $this->converter->toRgb($color);

        if ($space !== 'rgb' && $space !== 'srgb') {
            $channels  = $this->spaceInterop->rgbToWorkingSpaceChannels($rgb, $space);
            $inverted1 = 1.0 - $channels[0];
            $inverted2 = 1.0 - $channels[1];
            $inverted3 = 1.0 - $channels[2];

            $mixed1 = $this->colorSpaceConverter->mixChannel($channels[0], $inverted1, 1.0 - $p);
            $mixed2 = $this->colorSpaceConverter->mixChannel($channels[1], $inverted2, 1.0 - $p);
            $mixed3 = $this->colorSpaceConverter->mixChannel($channels[2], $inverted3, 1.0 - $p);

            return $this->astWriter->serializeRgbResult(
                $this->spaceInterop->workingSpaceChannelsToRgb($space, [$mixed1, $mixed2, $mixed3], $rgb->a)
            );
        }

        $invertedColor = $this->manipulator->invert(new IrisColorValue($rgb), $p);

        /** @var ColorValueInterface<RgbColor> $invertedColor */
        $invertedRgb = $invertedColor->getInner();

        return $this->astWriter->serializeRgbResult($invertedRgb);
    }

    public function extractNativeOrConvertedOklchColor(AstNode $color): OklchColor
    {
        return $this->astReader->extractOklch($color, 'color');
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
        callable $modify
    ): AstNode {
        $requestedSpace = null;

        if (isset($named['space'])) {
            $requestedSpace = strtolower($this->parser->asString($named['space'], $context));
            unset($named['space']);
        }

        $color        = $this->parser->requireColor($positional, 0, $context);
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

        $modifiedColor = $context === 'change-color'
            ? $this->manipulator->changeColor(new IrisColorValue($rgb), $values)
            : $this->manipulator->adjustColor(new IrisColorValue($rgb), $values);

        /** @var ColorValueInterface<RgbColor> $modifiedColor */
        $modifiedRgb = $modifiedColor->getInner();

        return $this->astWriter->serializeRgbResult($modifiedRgb);
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInOklchSpace(AstNode $color, array $named, callable $modify): AstNode
    {
        $isNativeOklch = $this->astReader->isNativeOklch($color);
        $baseColor     = $this->astReader->createBaseOklchColor($color, $isNativeOklch);

        $values = [
            'lightness' => $this->parsePercentageChannel($named, 'lightness', 'color'),
            'chroma'    => $this->parseNumberChannel($named, 'chroma', 'color'),
            'hue'       => $this->parseNumberChannel($named, 'hue', 'color'),
            'alpha'     => $this->parseNumberChannel($named, 'alpha', 'color'),
        ];

        $newOklchColor = $this->isDirectChange($modify)
            ? $this->manipulator->changeOklch(new IrisColorValue($baseColor), $values)
            : $this->manipulator->adjustOklch(new IrisColorValue($baseColor), $values);

        /** @var ColorValueInterface<OklchColor> $newOklchColor */
        $newOklchInner = $newOklchColor->getInner();

        if ($isNativeOklch) {
            return $this->astWriter->serializeAsOklchString($newOklchInner);
        }

        return $this->astWriter->serializeAsFloatRgb($this->colorSpaceConverter->oklchToSrgb($newOklchInner));
    }

    /**
     * @param array<string, AstNode> $named
     * @param callable(float, float): float $modify
     */
    private function applyInLabSpace(AstNode $color, array $named, callable $modify, bool $isLegacy): AstNode
    {
        $values = $this->buildLabChannelValues($named);

        if ($this->astReader->isNativeLab($color)) {
            /** @var FunctionNode $color */
            $lab = $this->astReader->readNativeLab($color);

            $newLabColor = $this->isDirectChange($modify)
                ? $this->manipulator->changeLab(new IrisColorValue($lab), $values)
                : $this->manipulator->adjustLab(new IrisColorValue($lab), $values);

            /** @var ColorValueInterface<LabColor> $newLabColor */
            $newLabInner = $newLabColor->getInner();

            if (! $isLegacy) {
                return $this->astWriter->serializeAsLabString($newLabInner);
            }

            return $this->serializeLabAsFloatRgb($newLabInner);
        }

        $lab = $this->colorSpaceConverter->xyzD50ToLabColor(
            $this->converter->toXyzD50($color),
            $this->converter->toAlpha($color)
        );

        $newLabColor = $this->isDirectChange($modify)
            ? $this->manipulator->changeLab(new IrisColorValue($lab), $values)
            : $this->manipulator->adjustLab(new IrisColorValue($lab), $values);

        /** @var ColorValueInterface<LabColor> $newLabColor */
        $newLabInner = $newLabColor->getInner();

        $newRgb = $this->astReader->convertLabToRgb($newLabInner);

        return $isLegacy ? $this->astWriter->fromRgb($newRgb) : $this->serializeFloatRgbFromByteRgb($newRgb);
    }

    private function formatColorAdjustHint(AstNode $color, string $channel, string $formattedAmount): string
    {
        return $this->formatColorFunctionHint('color.adjust', $color, $channel, $formattedAmount);
    }

    private function formatColorFunctionHint(
        string $function,
        AstNode $color,
        string $channel,
        string $formattedAmount
    ): string {
        return sprintf(
            '%s(%s, $%s: %s)',
            $function,
            $this->formatter->describeValue($color),
            $channel,
            $formattedAmount
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
            ? $this->manipulator->changeSrgb($r, $g, $b, $values)
            : $this->manipulator->adjustSrgb($r, $g, $b, $values);

        return $this->astWriter->serializeAsSrgbString($newR, $newG, $newB);
    }

    /** @param array<string, AstNode> $named */
    private function parseScalePercentage(array $named, string $channel): ?float
    {
        if (! array_key_exists($channel, $named)) {
            return null;
        }

        return $this->parser->asPercentage($named[$channel], 'scale-color');
    }

    /** @param array<string, AstNode> $named */
    private function parseNumberChannel(array $named, string $channel, string $context): ?float
    {
        if (! array_key_exists($channel, $named)) {
            return null;
        }

        return $this->parser->asNumber($named[$channel], $context);
    }

    /** @param array<string, AstNode> $named */
    private function parsePercentageChannel(array $named, string $channel, string $context): ?float
    {
        if (! array_key_exists($channel, $named)) {
            return null;
        }

        return $this->parser->asPercentage($named[$channel], $context);
    }

    private function createOklchFromRgb(RgbColor $rgb): OklchColor
    {
        return $this->astReader->createOklchFromRgb($rgb);
    }

    private function serializeLabAsFloatRgb(LabColor $lab): AstNode
    {
        return $this->serializeFloatRgbFromByteRgb($this->astReader->convertLabToRgb($lab));
    }

    private function serializeFloatRgbFromByteRgb(RgbColor $rgb): AstNode
    {
        return $this->astWriter->serializeAsFloatRgb(
            new RgbColor(
                r: $rgb->rValue() / 255.0,
                g: $rgb->gValue() / 255.0,
                b: $rgb->bValue() / 255.0,
                a: $rgb->a
            )
        );
    }

    /** @return array{0: float, 1: float, 2: float} */
    private function extractSrgbChannels(AstNode $color): array
    {
        return $this->astReader->extractSrgbChannels($color);
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
            $this->formatter->formatSignedPercentage($direction > 0 ? $scale : -$scale)
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
                static fn(string $part): bool => $part !== ''
            )
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
        float $p
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

        return ['value' => $this->colorSpaceConverter->mixChannel($left, $right, $p), 'missing' => false];
    }

    /** @return array{value: float, missing: bool} */
    private function mixPossiblyMissingHue(
        float $left,
        float $right,
        bool $leftMissing,
        bool $rightMissing,
        float $p,
        ?string $method
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
                $mixed[] = $this->colorSpaceConverter->trimFloat($right, 10);

                continue;
            }

            if ($right === null) {
                $mixed[] = $this->colorSpaceConverter->trimFloat($left, 10);

                continue;
            }

            $mixed[] = $this->colorSpaceConverter->trimFloat(
                $this->colorSpaceConverter->mixChannel($left, $right, $p),
                10
            );
        }

        return $this->astWriter->buildFunctionalColorNode('color', [
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
        $channels = $this->colorSpaceConverter->rgbToRec2020($this->converter->toRgb($color));

        return [$channels[0], $channels[1], $channels[2]];
    }

    private function extractOptionalNumericChannel(?AstNode $node): ?float
    {
        if ($node === null || $this->parser->isMissingChannelNode($node)) {
            return null;
        }

        if ($node instanceof NumberNode && $node->unit === '%') {
            return $this->parser->clamp((float) $node->value / 100.0, 1.0);
        }

        return $this->parser->clamp($this->parser->asNumber($node, 'mix'), 1.0);
    }

    private function interpolateHue(float $h1, float $h2, float $p, ?string $method = null): float
    {
        return $this->colorMixResolver->mixOklch(
            new OklchColor(0.0, 0.0, $h1),
            new OklchColor(0.0, 0.0, $h2),
            $p,
            $method ?? 'shorter'
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

        $mixedHsl = $this->colorMixResolver->mixHsl(
            new HslColor($h1, $hsl1->s, $hsl1->l, $hsl1->a),
            new HslColor($h2, $hsl2->s, $hsl2->l, $hsl2->a),
            $p,
            $hueMethod ?? 'shorter'
        );

        $hue = $mixedHsl->hValue();
        $sat = $this->parser->clamp($this->colorSpaceConverter->mixChannel($hsl1->s, $hsl2->s, $p), 100.0);
        $lig = $this->parser->clamp($this->colorSpaceConverter->mixChannel($hsl1->l, $hsl2->l, $p), 100.0);
        $alp = $mixedHsl->a;

        return $this->astWriter->buildHslFunctionNode($hue, $sat, $lig, $alp);
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
            $p
        );

        $chroma = $this->mixPossiblyMissingChannel(
            $oklch1['c'],
            $oklch2['c'],
            $oklch1['c_missing'],
            $oklch2['c_missing'],
            $p
        );

        $hue = $this->mixPossiblyMissingHue(
            $oklch1['h'],
            $oklch2['h'],
            $oklch1['h_missing'],
            $oklch2['h_missing'],
            $p,
            $hueMethod
        );

        $mix = new OklchColor(
            l: $lightness['value'],
            c: $chroma['value'],
            h: $hue['value'],
            a: $this->colorSpaceConverter->mixChannel($oklch1['a'], $oklch2['a'], $p),
        );

        if (
            $this->converter->detectNativeColorSpace($color1) === 'oklch'
            && $this->converter->detectNativeColorSpace($color2) === 'oklch'
        ) {
            return $this->astWriter->buildFunctionalColorNode('oklch', [
                $lightness['missing'] ? new StringNode('none') : new NumberNode($mix->lValue(), '%'),
                $chroma['missing'] ? new StringNode('none') : new NumberNode($mix->cValue()),
                $hue['missing'] ? new StringNode('none') : new NumberNode($mix->hValue(), 'deg'),
            ], $mix->a);
        }

        return $this->astWriter->serializeLegacyRgbFunction($this->colorSpaceConverter->oklchToSrgb($mix));
    }
}
