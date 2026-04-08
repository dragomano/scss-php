<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Converters\NormalizedRgbChannels;
use Bugo\Iris\Spaces\HslColor;
use Bugo\Iris\Spaces\HwbColor;
use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\OklabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\Iris\Spaces\XyzColor;
use Bugo\SCSS\Builtins\Color\Support\ColorRuntime;
use Bugo\SCSS\Exceptions\MissingFunctionArgumentsException;
use Bugo\SCSS\Exceptions\UnsupportedColorValueException;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Values\AstValueInspector;

use function abs;
use function in_array;
use function max;
use function round;
use function str_contains;
use function str_starts_with;
use function strtolower;
use function trim;

final readonly class ColorNodeConverter
{
    public function __construct(private ColorRuntime $runtime) {}

    public function toRgb(AstNode $color): RgbColor
    {
        if ($color instanceof FunctionNode) {
            $rgba = $this->runtime->cssColorFunctionConverter->tryConvertToRgba($color);

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
                a: $rgba->a,
            );
        }

        if (! ($color instanceof ColorNode || $color instanceof StringNode)) {
            throw new MissingFunctionArgumentsException(
                $this->runtime->context->errorCtx('color'),
                'color arguments',
            );
        }

        $parsed = $this->runtime->literalParser->toRgb($color->value);

        if ($parsed !== null) {
            return $parsed;
        }

        if ($color instanceof StringNode) {
            $parsedColor = $this->runtime->colorAstParser->parse($color->value);

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

        return $this->runtime->spaceConverter->rgbToXyzD65($this->toRgb($color));
    }

    public function toXyzD50(AstNode $color): XyzColor
    {
        if ($color instanceof FunctionNode) {
            $xyz = $this->runtime->cssColorFunctionConverter->tryConvertToXyzD50($color);

            if ($xyz !== null) {
                return $xyz[0];
            }
        }

        return $this->runtime->spaceConverter->rgbToXyzD50($this->toRgb($color));
    }

    /**
     * @return array{0: XyzColor, 1: float}|null
     */
    public function toXyzD65WithAlpha(FunctionNode $color): ?array
    {
        return $this->runtime->cssColorFunctionConverter->tryConvertToXyzD65($color);
    }

    public function toHsl(AstNode $color): HslColor
    {
        return $this->runtime->modelConverter->rgbToHslColor($this->toRgb($color));
    }

    public function toUnclampedRgb(AstNode $color): RgbColor
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'color') {
            $space = $this->detectGenericColorSpace($color);

            if ($space === 'srgb') {
                $channels = $this->extractChannelNodes($color);
                $red      = $channels[1] ?? null;
                $green    = $channels[2] ?? null;
                $blue     = $channels[3] ?? null;

                if (
                    $red instanceof NumberNode
                    && $green instanceof NumberNode
                    && $blue instanceof NumberNode
                    && ($red->unit === null || $red->unit === '' || $red->unit === '%')
                    && ($green->unit === null || $green->unit === '' || $green->unit === '%')
                    && ($blue->unit === null || $blue->unit === '' || $blue->unit === '%')
                ) {
                    return new RgbColor(
                        r: $this->genericSrgbChannelToByte($red),
                        g: $this->genericSrgbChannelToByte($green),
                        b: $this->genericSrgbChannelToByte($blue),
                        a: $this->toAlpha($color),
                    );
                }
            }
        }

        return $this->toRgb($color);
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
            h: $this->runtime->spaceConverter->hueFromNormalizedRgb(
                new NormalizedRgbChannels(
                    r: $r,
                    g: $g,
                    b: $b,
                    a: 1.0,
                    max: $max,
                    min: $min,
                    delta: $delta,
                ),
            ),
            w: $min * 100.0,
            b: (1.0 - $max) * 100.0,
            a: $rgb->a,
        );
    }

    public function parseColorString(string $value): FunctionNode|ColorNode|null
    {
        return $this->runtime->colorAstParser->parse($value);
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
        $spaceNode = $this->runtime->arguments->expandArguments($color)[0] ?? null;

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
        [$channels] = $this->extractRawChannels($fn);

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

            if (empty($node) || AstValueInspector::isNoneKeyword($node) || ! ($node instanceof NumberNode)) {
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

    public function readNativeOklch(FunctionNode $color): OklchColor
    {
        $channels = $this->extractChannelNodes($color);

        return new OklchColor(
            l: $this->runtime->argumentParser->asPercentage($channels[0] ?? null, 'color'),
            c: $this->runtime->argumentParser->asNumber($channels[1] ?? null, 'color'),
            h: $this->runtime->argumentParser->asNumber($channels[2] ?? null, 'color'),
            a: 1.0,
        );
    }

    public function readNativeLab(FunctionNode $color): LabColor
    {
        $channels = $this->extractChannelNodes($color);

        return new LabColor(
            l: $this->runtime->argumentParser->asPercentage($channels[0] ?? null, 'color'),
            a: $this->runtime->argumentParser->asNumber($channels[1] ?? null, 'color'),
            b: $this->runtime->argumentParser->asNumber($channels[2] ?? null, 'color'),
            alpha: 1.0,
        );
    }

    public function createOklchFromRgb(RgbColor $rgb): OklchColor
    {
        $oklch = $this->runtime->spaceConverter->rgbToOklch($rgb);

        return new OklchColor(l: $oklch->l, c: $oklch->c, h: $oklch->h, a: $rgb->a);
    }

    public function createBaseOklchColor(AstNode $color): OklchColor
    {
        if ($this->isNativeSpace($color, 'oklch')) {
            /** @var FunctionNode $color */
            return $this->readNativeOklch($color);
        }

        return $this->createOklchFromRgb($this->toRgb($color));
    }

    public function convertLabToRgb(LabColor $lab): RgbColor
    {
        return $this->runtime->spaceConverter->labToRgbColor($lab);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function extractSrgbChannels(AstNode $color): array
    {
        if (! ($color instanceof FunctionNode) || $color->name !== 'color') {
            $rgb = $this->toRgb($color);

            return [$rgb->rValue() / 255.0, $rgb->gValue() / 255.0, $rgb->bValue() / 255.0];
        }

        $channels = $this->extractChannelNodes($color);

        return [
            $this->runtime->argumentParser->asNumber($channels[1] ?? null, 'color'),
            $this->runtime->argumentParser->asNumber($channels[2] ?? null, 'color'),
            $this->runtime->argumentParser->asNumber($channels[3] ?? null, 'color'),
        ];
    }

    public function isNativeSpace(AstNode $color, string $space): bool
    {
        return $color instanceof FunctionNode && strtolower($color->name) === $space;
    }

    public function extractOklch(AstNode $color, string $context): OklchColor
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
            [$channels, $alpha] = $this->extractRawChannels($color);

            return new OklchColor(
                l: $this->parseLightness($channels[0] ?? null, $context),
                c: $this->parseChroma($channels[1] ?? null, $context),
                h: $this->parseHue($channels[2] ?? null, $context),
                a: $this->parseAlpha($alpha, $context),
            );
        }

        return $this->runtime->spaceConverter->rgbToOklch($this->toRgb($color));
    }

    /**
     * @return array{l: float, c: float, h: float, a: float, l_missing: bool, c_missing: bool, h_missing: bool}
     */
    public function extractOklchMixData(AstNode $color, string $context): array
    {
        if ($color instanceof FunctionNode && strtolower($color->name) === 'oklch') {
            [$channels, $alpha] = $this->extractRawChannels($color);

            $lightnessMissing = $this->isMissing($channels[0] ?? null);
            $chromaMissing    = $this->isMissing($channels[1] ?? null);
            $hueMissing       = $this->isMissing($channels[2] ?? null);

            return [
                'l'         => $lightnessMissing ? 0.0 : $this->parseLightness($channels[0] ?? null, $context),
                'c'         => $chromaMissing ? 0.0 : $this->parseChroma($channels[1] ?? null, $context),
                'h'         => $hueMissing ? 0.0 : $this->parseHue($channels[2] ?? null, $context),
                'a'         => $this->parseAlpha($alpha, $context),
                'l_missing' => $lightnessMissing,
                'c_missing' => $chromaMissing,
                'h_missing' => $hueMissing,
            ];
        }

        $oklch = $this->runtime->spaceConverter->rgbToOklch($this->toRgb($color));

        return [
            'l'         => $oklch->lValue(),
            'c'         => $oklch->cValue(),
            'h'         => $oklch->hValue(),
            'a'         => $oklch->a,
            'l_missing' => false,
            'c_missing' => false,
            'h_missing' => false,
        ];
    }

    public function fromRgb(RgbColor $rgb): ColorNode
    {
        return new ColorNode($this->runtime->literalSerializer->serialize($rgb));
    }

    public function serializeRgbResult(RgbColor $rgb): AstNode
    {
        $hasFractionalChannels = abs($rgb->rValue() - round($rgb->rValue())) > 0.0000001
            || abs($rgb->gValue() - round($rgb->gValue())) > 0.0000001
            || abs($rgb->bValue() - round($rgb->bValue())) > 0.0000001;

        if (! $hasFractionalChannels && abs($rgb->a - 1.0) < 0.0000001) {
            return $this->fromRgb($rgb);
        }

        $r = new NumberNode(
            (float) $this->runtime->spaceConverter->trimFloat(
                $this->runtime->spaceConverter->clamp($rgb->r, 255.0),
                10,
            ),
        );

        $g = new NumberNode(
            (float) $this->runtime->spaceConverter->trimFloat(
                $this->runtime->spaceConverter->clamp($rgb->g, 255.0),
                10,
            ),
        );

        $b = new NumberNode(
            (float) $this->runtime->spaceConverter->trimFloat(
                $this->runtime->spaceConverter->clamp($rgb->b, 255.0),
                10,
            ),
        );

        if (abs($rgb->a - 1.0) < 0.0000001) {
            return new FunctionNode('rgb', [$r, $g, $b]);
        }

        $alpha = new NumberNode(
            (float) $this->runtime->spaceConverter->trimFloat(
                $this->runtime->spaceConverter->clamp($rgb->a, 1.0),
                10,
            ),
        );

        return new FunctionNode('rgba', [$r, $g, $b, $alpha]);
    }

    public function serializeLegacyRgbFunction(RgbColor $rgb): FunctionNode
    {
        $r = new NumberNode($this->runtime->spaceConverter->roundFloat($rgb->rValue() * 255.0));
        $g = new NumberNode($this->runtime->spaceConverter->roundFloat($rgb->gValue() * 255.0));
        $b = new NumberNode($this->runtime->spaceConverter->roundFloat($rgb->bValue() * 255.0));

        if (abs($rgb->a - 1.0) < 0.000001) {
            return new FunctionNode('rgb', [$r, $g, $b]);
        }

        return new FunctionNode(
            'rgba',
            [$r, $g, $b, new NumberNode($this->runtime->spaceConverter->roundFloat($rgb->a))],
        );
    }

    public function serializeRgbFromAstSource(AstNode $source, RgbColor $byteRgb): AstNode
    {
        $hasFractional = abs($byteRgb->rValue() - round($byteRgb->rValue())) > 0.0000001
            || abs($byteRgb->gValue() - round($byteRgb->gValue())) > 0.0000001
            || abs($byteRgb->bValue() - round($byteRgb->bValue())) > 0.0000001
            || abs($byteRgb->a - 1.0) > 0.0000001;

        if (! $hasFractional && $source instanceof ColorNode) {
            return $this->fromRgb($byteRgb);
        }

        return $this->serializeAsFloatRgb(new RgbColor(
            r: $byteRgb->rValue() / 255.0,
            g: $byteRgb->gValue() / 255.0,
            b: $byteRgb->bValue() / 255.0,
            a: $byteRgb->a,
        ));
    }

    public function serializeAsFloatRgb(RgbColor $rgb): FunctionNode
    {
        return $this->buildRgbFunctionNode(
            $rgb->rValue() * 255.0,
            $rgb->gValue() * 255.0,
            $rgb->bValue() * 255.0,
            $rgb->a,
        );
    }

    public function serializeAsOklchString(OklchColor $oklch, bool $zeroChromaAsPercent = false): FunctionNode
    {
        $c = $zeroChromaAsPercent && $oklch->cValue() <= 0.0
            ? new NumberNode(0, '%')
            : new NumberNode($oklch->cValue());

        return $this->buildFunctionalColorNode('oklch', [
            new NumberNode($oklch->lValue(), '%'),
            $c,
            new NumberNode($oklch->hValue(), 'deg'),
        ], $oklch->a);
    }

    public function buildLabColorNode(LabColor $lab): FunctionNode
    {
        return $this->buildFunctionalColorNode('lab', [
            new NumberNode($lab->lValue(), '%'),
            new NumberNode($lab->aValue()),
            new NumberNode($lab->bValue()),
        ], $lab->alpha);
    }

    public function buildLchColorNode(LchColor $lch, float $alpha): FunctionNode
    {
        return $this->buildFunctionalColorNode('lch', [
            new NumberNode($lch->lValue(), '%'),
            new NumberNode($lch->cValue()),
            new NumberNode($lch->hValue(), 'deg'),
        ], $alpha);
    }

    public function buildOklabColorNode(OklabColor $oklab): FunctionNode
    {
        return $this->buildFunctionalColorNode('oklab', [
            new NumberNode($oklab->lValue(), '%'),
            new NumberNode($oklab->aValue()),
            new NumberNode($oklab->bValue()),
        ], $oklab->alpha);
    }

    /** @param array{0: float, 1: float, 2: float} $channels */
    public function buildGenericColorFunctionNode(string $space, array $channels, float $alpha): FunctionNode
    {
        return $this->buildFunctionalColorNode('color', [
            new StringNode($space),
            new NumberNode($channels[0]),
            new NumberNode($channels[1]),
            new NumberNode($channels[2]),
        ], $alpha);
    }

    public function serializeAsSrgbString(float $r, float $g, float $b): FunctionNode
    {
        return $this->buildFunctionalColorNode('color', [
            new StringNode('srgb'),
            new NumberNode($r),
            new NumberNode($g),
            new NumberNode($b),
        ], 1.0);
    }

    public function buildHslFunctionNode(float $hue, float $saturation, float $lightness, float $alpha): FunctionNode
    {
        $arguments = [
            new NumberNode($this->runtime->spaceConverter->normalizeHue($hue)),
            new NumberNode($this->runtime->spaceConverter->roundFloat($saturation), '%'),
            new NumberNode($this->runtime->spaceConverter->roundFloat($lightness), '%'),
        ];

        if (abs($alpha - 1.0) >= 0.000001) {
            return new FunctionNode(
                'hsla',
                [$arguments[0], $arguments[1], $arguments[2], $this->buildAlphaNode($alpha)],
            );
        }

        return new FunctionNode('hsl', $arguments);
    }

    public function buildRgbFunctionNode(float $red, float $green, float $blue, float $alpha): FunctionNode
    {
        $arguments = [new NumberNode($red), new NumberNode($green), new NumberNode($blue)];

        if (abs($alpha - 1.0) >= 0.000001) {
            return new FunctionNode(
                'rgba',
                [$arguments[0], $arguments[1], $arguments[2], new NumberNode($alpha)],
            );
        }

        return new FunctionNode('rgb', $arguments);
    }

    /** @param list<AstNode> $channels */
    public function buildFunctionalColorNode(string $name, array $channels, float $alpha): FunctionNode
    {
        return new FunctionNode(
            $name,
            [new ListNode($this->appendSlashAlphaTail($channels, $alpha), 'space')],
        );
    }

    /**
     * @param list<AstNode> $channels
     * @return list<AstNode>
     */
    public function appendSlashAlphaTail(array $channels, float $alpha): array
    {
        if ($alpha >= 1.0) {
            return $channels;
        }

        $channels[] = new StringNode('/');
        $channels[] = $this->buildAlphaNode($alpha);

        return $channels;
    }

    public function buildAlphaNode(float $alpha): NumberNode
    {
        return new NumberNode($this->runtime->spaceConverter->roundFloat($alpha));
    }

    /**
     * @return array{0: array<int, AstNode>, 1: ?AstNode}
     */
    private function extractRawChannels(FunctionNode $color): array
    {
        return $this->runtime->arguments->splitChannelsAndAlpha(
            $this->runtime->arguments->expandArguments($color),
            false,
        );
    }

    private function parseLightness(?AstNode $node, string $context): float
    {
        return $this->runtime->argumentParser->clamp(
            $this->runtime->argumentParser->asPercentage($node, $context),
            100.0,
        );
    }

    private function parseChroma(?AstNode $node, string $context): float
    {
        return max(0.0, $this->runtime->argumentParser->asAbsoluteChannel($node, $context, 0.4));
    }

    private function parseHue(?AstNode $node, string $context): float
    {
        return $this->runtime->argumentParser->normalizeHue(
            $this->runtime->argumentParser->asHueAngle($node, $context),
        );
    }

    private function parseAlpha(?AstNode $node, string $context): float
    {
        if ($node === null) {
            return 1.0;
        }

        return $this->runtime->argumentParser->clamp(
            $this->runtime->argumentParser->asNumber($node, $context),
            1.0,
        );
    }

    private function isMissing(?AstNode $node): bool
    {
        return $node !== null && $this->runtime->argumentParser->isMissingChannelNode($node);
    }

    private function genericSrgbChannelToByte(NumberNode $channel): float
    {
        $value = (float) $channel->value;

        if ($channel->unit === '%') {
            return $value * 255.0 / 100.0;
        }

        return $value * 255.0;
    }
}
