<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Support;

final readonly class ColorChannelSchema
{
    private const FUNCTION_CHANNELS = [
        'rgb'   => ['red' => 0, 'green' => 1, 'blue' => 2, 'alpha' => 3],
        'rgba'  => ['red' => 0, 'green' => 1, 'blue' => 2, 'alpha' => 3],
        'hsl'   => ['hue' => 0, 'saturation' => 1, 'lightness' => 2, 'alpha' => 3],
        'hsla'  => ['hue' => 0, 'saturation' => 1, 'lightness' => 2, 'alpha' => 3],
        'hwb'   => ['hue' => 0, 'whiteness' => 1, 'blackness' => 2, 'alpha' => 3],
        'oklch' => ['lightness' => 0, 'chroma' => 1, 'hue' => 2, 'alpha' => 3],
        'oklab' => ['lightness' => 0, 'a' => 1, 'b' => 2, 'alpha' => 3],
        'lch'   => ['lightness' => 0, 'chroma' => 1, 'hue' => 2, 'alpha' => 3],
        'lab'   => ['lightness' => 0, 'a' => 1, 'b' => 2, 'alpha' => 3],
    ];

    public function indexForChannel(string $functionName, string $channelName): ?int
    {
        return self::FUNCTION_CHANNELS[$functionName][$channelName] ?? null;
    }

    public function lightnessIndexForFunction(string $functionName): ?int
    {
        return $this->indexForChannel($functionName, 'lightness');
    }
}
