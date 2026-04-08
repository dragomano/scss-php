<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

use Bugo\Iris\Converters\SpaceConverter;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\StringNode;

use function abs;
use function implode;

final readonly class ColorValueFormatter
{
    public function __construct(private SpaceConverter $colorSpaceConverter) {}

    public function describeValue(AstNode $value): string
    {
        if ($value instanceof ColorNode || $value instanceof StringNode) {
            return $value->value;
        }

        if ($value instanceof NumberNode) {
            return $this->formatNumberNode($value);
        }

        if ($value instanceof FunctionNode) {
            $arguments = [];

            foreach ($value->arguments as $argument) {
                $arguments[] = $this->describeValue($argument);
            }

            return $value->name . '(' . implode(', ', $arguments) . ')';
        }

        if ($value instanceof ListNode) {
            $items = [];

            foreach ($value->items as $item) {
                $items[] = $this->describeValue($item);
            }

            return implode(' ', $items);
        }

        return '';
    }

    public function formatDegrees(float $degrees): string
    {
        return $this->formatPlainNumber($degrees) . 'deg';
    }

    public function formatSignedNumber(float $value): string
    {
        return ($value > 0.0 ? '' : ($value < 0.0 ? '-' : '')) . $this->formatPlainNumber(abs($value));
    }

    public function formatSignedPercentage(float $value): string
    {
        return ($value > 0.0 ? '' : ($value < 0.0 ? '-' : '')) . $this->formatPlainNumber(abs($value)) . '%';
    }

    public function formatNumberNode(NumberNode $number): string
    {
        return $this->formatPlainNumber((float) $number->value) . ($number->unit ?? '');
    }

    public function formatPlainNumber(float $value): string
    {
        return $this->colorSpaceConverter->trimFloat($value, 10);
    }
}
