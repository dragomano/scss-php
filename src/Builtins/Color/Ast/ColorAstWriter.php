<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Ast;

use Bugo\Iris\Spaces\LabColor;
use Bugo\Iris\Spaces\LchColor;
use Bugo\Iris\Spaces\OklabColor;
use Bugo\Iris\Spaces\OklchColor;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Builtins\Color\Adapters\IrisColorValue;
use Bugo\SCSS\Contracts\Color\ColorConverterInterface;
use Bugo\SCSS\Contracts\Color\ColorLiteralInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

use function abs;
use function round;

final readonly class ColorAstWriter
{
    public function __construct(
        private ColorConverterInterface $colorSpaceConverter,
        private ColorLiteralInterface $colorLiteralSerializer
    ) {}

    public function fromRgb(RgbColor $rgb): ColorNode
    {
        return new ColorNode($this->colorLiteralSerializer->serialize(new IrisColorValue($rgb)));
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
            (float) $this->colorSpaceConverter->trimFloat(
                $this->colorSpaceConverter->clamp($rgb->r, 255.0),
                10
            )
        );

        $g = new NumberNode(
            (float) $this->colorSpaceConverter->trimFloat(
                $this->colorSpaceConverter->clamp($rgb->g, 255.0),
                10
            )
        );

        $b = new NumberNode(
            (float) $this->colorSpaceConverter->trimFloat(
                $this->colorSpaceConverter->clamp($rgb->b, 255.0),
                10
            )
        );

        if (abs($rgb->a - 1.0) < 0.0000001) {
            return new FunctionNode('rgb', [$r, $g, $b]);
        }

        $alpha = new NumberNode(
            (float) $this->colorSpaceConverter->trimFloat(
                $this->colorSpaceConverter->clamp($rgb->a, 1.0),
                10
            )
        );

        return new FunctionNode('rgba', [$r, $g, $b, $alpha]);
    }

    public function serializeLegacyRgbFunction(RgbColor $rgb): FunctionNode
    {
        $r = new NumberNode($this->colorSpaceConverter->roundFloat($rgb->rValue() * 255.0));
        $g = new NumberNode($this->colorSpaceConverter->roundFloat($rgb->gValue() * 255.0));
        $b = new NumberNode($this->colorSpaceConverter->roundFloat($rgb->bValue() * 255.0));

        if (abs($rgb->a - 1.0) < 0.000001) {
            return new FunctionNode('rgb', [$r, $g, $b]);
        }

        return new FunctionNode(
            'rgba',
            [$r, $g, $b, new NumberNode($this->colorSpaceConverter->roundFloat($rgb->a))]
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
            $rgb->a
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

    public function serializeAsLabString(LabColor $lab): FunctionNode
    {
        return $this->buildLabColorNode($lab);
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
            new NumberNode($this->colorSpaceConverter->normalizeHue($hue)),
            new NumberNode($this->roundFloat($saturation), '%'),
            new NumberNode($this->roundFloat($lightness), '%'),
        ];

        if (abs($alpha - 1.0) >= 0.000001) {
            return new FunctionNode(
                'hsla',
                [$arguments[0], $arguments[1], $arguments[2], $this->buildAlphaNode($alpha)]
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
                [$arguments[0], $arguments[1], $arguments[2], new NumberNode($alpha)]
            );
        }

        return new FunctionNode('rgb', $arguments);
    }

    /** @param list<AstNode> $channels */
    public function buildFunctionalColorNode(string $name, array $channels, float $alpha): FunctionNode
    {
        return new FunctionNode(
            $name,
            [new ListNode($this->appendSlashAlphaTail($channels, $alpha), 'space')]
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
        return new NumberNode($this->roundFloat($alpha));
    }

    public function serializeAsOklchColorFunction(OklchColor $oklch): FunctionNode
    {
        return $this->buildFunctionalColorNode('oklch', [
            new NumberNode($oklch->lValue(), '%'),
            new NumberNode($oklch->cValue()),
            new NumberNode($oklch->hValue(), 'deg'),
        ], $oklch->a);
    }

    public function roundFloat(float $value): float
    {
        return $this->colorSpaceConverter->roundFloat($value);
    }
}
