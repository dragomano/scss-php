<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Conversion;

use Bugo\Iris\Encoders\HexEncoder;
use Bugo\Iris\Encoders\HexShortener;
use Bugo\SCSS\Builtins\Color\Support\ColorChannelSplitterTrait;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;

use function abs;
use function in_array;
use function max;
use function min;
use function round;
use function strtolower;

final readonly class HexColorConverter
{
    use ColorChannelSplitterTrait {
        splitChannelsAndAlpha as public;
    }

    public function __construct(
        private HexEncoder $hexColorEncoder = new HexEncoder(),
        private HexShortener $hexColorShortener = new HexShortener(),
        private CssColorFunctionConverter $cssColorFunctionConverter = new CssColorFunctionConverter()
    ) {}

    public function tryConvert(FunctionNode $function): ?ColorNode
    {
        if (! $this->canConvertWithoutPrecisionLoss($function)) {
            return null;
        }

        $rgba = $this->cssColorFunctionConverter->tryConvertToRgba($function);

        if ($rgba === null) {
            return null;
        }

        return $this->rgbaToHexColorNode($rgba->rValue(), $rgba->gValue(), $rgba->bValue(), $rgba->a);
    }

    /**
     * @return array<int, AstNode>
     */
    public function expandArguments(FunctionNode $function): array
    {
        if (
            count($function->arguments) === 1
            && $function->arguments[0] instanceof ListNode
            && $function->arguments[0]->separator === 'space'
        ) {
            return $function->arguments[0]->items;
        }

        return $function->arguments;
    }

    private function canConvertWithoutPrecisionLoss(FunctionNode $function): bool
    {
        $name = strtolower($function->name);

        if (in_array($name, ['color', 'lab', 'lch', 'oklab', 'oklch'], true)) {
            return false;
        }

        if ($name === 'rgb' || $name === 'rgba') {
            return $this->hasLosslessRgbChannels($function);
        }

        return true;
    }

    private function hasLosslessRgbChannels(FunctionNode $function): bool
    {
        [$channels] = $this->splitChannelsAndAlpha($this->expandArguments($function));

        if (count($channels) !== 3) {
            return false;
        }

        foreach ($channels as $channel) {
            if (! $this->isLosslessRgbChannel($channel)) {
                return false;
            }
        }

        return true;
    }

    private function isLosslessRgbChannel(AstNode $channel): bool
    {
        if (! $channel instanceof NumberNode) {
            return false;
        }

        if ($channel->unit === '') {
            $value = (float) $channel->value;

            return $value >= 0.0
                && $value <= 255.0
                && abs($value - round($value)) < 0.000001;
        }

        if ($channel->unit === '%') {
            $value     = (float) $channel->value;
            $byteValue = $value * 255.0 / 100.0;

            return $byteValue >= 0.0
                && $byteValue <= 255.0
                && abs($byteValue - round($byteValue)) < 0.000001;
        }

        return false;
    }

    private function rgbaToHexColorNode(float $red, float $green, float $blue, float $alpha): ColorNode
    {
        $rByte = (int) round($this->clampFloat($red) * 255.0);
        $gByte = (int) round($this->clampFloat($green) * 255.0);
        $bByte = (int) round($this->clampFloat($blue) * 255.0);
        $aByte = (int) round($this->clampFloat($alpha) * 255.0);

        $hex = $aByte < 255
            ? $this->hexColorEncoder->encodeRgba($rByte, $gByte, $bByte, $aByte)
            : $this->hexColorEncoder->encodeRgb($rByte, $gByte, $bByte);

        return new ColorNode($this->hexColorShortener->shorten($hex));
    }

    private function clampFloat(float $value): float
    {
        return max(0.0, min(1.0, $value));
    }
}
