<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Ast;

use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Conversion\ColorNodeConverter;
use Bugo\SCSS\Builtins\Color\Conversion\HexColorConverter;
use Bugo\SCSS\Builtins\Color\Support\ColorArgumentParser;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\StringNode;

use function max;
use function strtolower;

final readonly class ColorAstReader
{
    public function __construct(
        private ColorArgumentParser $parser,
        private ColorNodeConverter $converter,
        private HexColorConverter $hexColorConverter,
        private ColorConverterInterface $colorSpaceConverter,
    ) {}

    public function readNativeOklch(FunctionNode $color): OklchColor
    {
        $channels = $this->converter->extractChannelNodes($color);

        return new OklchColor(
            l: $this->parser->asPercentage($channels[0] ?? null, 'color'),
            c: $this->parser->asNumber($channels[1] ?? null, 'color'),
            h: $this->parser->asNumber($channels[2] ?? null, 'color'),
            a: 1.0,
        );
    }

    public function readNativeLab(FunctionNode $color): LabColor
    {
        $channels = $this->converter->extractChannelNodes($color);

        return new LabColor(
            l: $this->parser->asPercentage($channels[0] ?? null, 'color'),
            a: $this->parser->asNumber($channels[1] ?? null, 'color'),
            b: $this->parser->asNumber($channels[2] ?? null, 'color'),
            alpha: 1.0,
        );
    }

    public function createOklchFromRgb(RgbColor $rgb): OklchColor
    {
        $oklch = $this->colorSpaceConverter->rgbToOklch($rgb);

        return new OklchColor(l: $oklch->l, c: $oklch->c, h: $oklch->h, a: $rgb->a);
    }

    public function createBaseOklchColor(AstNode $color, bool $isNativeOklch): OklchColor
    {
        if ($isNativeOklch) {
            /** @var FunctionNode $color */
            return $this->readNativeOklch($color);
        }

        return $this->createOklchFromRgb($this->converter->toRgb($color));
    }

    public function convertLabToRgb(LabColor $lab): RgbColor
    {
        return $this->colorSpaceConverter->labToRgbColor($lab);
    }

    /**
     * @return array{0: float, 1: float, 2: float}
     */
    public function extractSrgbChannels(AstNode $color): array
    {
        if (! ($color instanceof FunctionNode) || $color->name !== 'color') {
            $rgb = $this->converter->toRgb($color);

            return [$rgb->rValue() / 255.0, $rgb->gValue() / 255.0, $rgb->bValue() / 255.0];
        }

        $channels = $this->converter->extractChannelNodes($color);

        return [
            $this->parser->asNumber($channels[1] ?? null, 'color'),
            $this->parser->asNumber($channels[2] ?? null, 'color'),
            $this->parser->asNumber($channels[3] ?? null, 'color'),
        ];
    }

    public function isNativeOklch(AstNode $color): bool
    {
        return $color instanceof FunctionNode && $color->name === 'oklch';
    }

    public function isNativeLab(AstNode $color): bool
    {
        return $color instanceof FunctionNode && $color->name === 'lab';
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

        return $this->colorSpaceConverter->rgbToOklch($this->converter->toRgb($color));
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

        $oklch = $this->colorSpaceConverter->rgbToOklch($this->converter->toRgb($color));

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

    /**
     * @return array{0: array<int, AstNode>, 1: ?AstNode}
     */
    private function extractRawChannels(FunctionNode $color): array
    {
        $items = $this->hexColorConverter->expandArguments($color);

        return $this->hexColorConverter->splitChannelsAndAlpha($items, false);
    }

    private function parseLightness(?AstNode $node, string $context): float
    {
        return $this->parser->clamp($this->parser->asPercentage($node, $context), 100.0);
    }

    private function parseChroma(?AstNode $node, string $context): float
    {
        return max(0.0, $this->parser->asAbsoluteChannel($node, $context, 0.4));
    }

    private function parseHue(?AstNode $node, string $context): float
    {
        return $this->parser->normalizeHue($this->parser->asHueAngle($node, $context));
    }

    private function parseAlpha(?AstNode $node, string $context): float
    {
        if ($node === null) {
            return 1.0;
        }

        return $this->parser->clamp($this->parser->asNumber($node, $context), 1.0);
    }

    private function isMissing(?AstNode $node): bool
    {
        return $this->parser->isMissingChannelNode($node ?? new StringNode('none'));
    }
}
