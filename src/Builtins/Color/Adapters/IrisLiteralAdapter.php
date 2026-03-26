<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Adapters;

use Bugo\Iris\LiteralParser;
use Bugo\Iris\Serializers\LiteralSerializer;
use Bugo\Iris\Spaces\RgbColor;
use Bugo\SCSS\Contracts\Color\ColorLiteralInterface;
use Bugo\SCSS\Contracts\Color\ColorValueInterface;
use LogicException;

final readonly class IrisLiteralAdapter implements ColorLiteralInterface
{
    public function __construct(
        private LiteralSerializer $literalSerializer = new LiteralSerializer(),
        private LiteralParser $literalConverter = new LiteralParser(),
    ) {}

    /** @return IrisColorValue<RgbColor>|null */
    public function parse(string $css): ?ColorValueInterface
    {
        $rgb = $this->literalConverter->toRgb($css);

        if ($rgb === null) {
            return null;
        }

        return $this->wrap($rgb);
    }

    /** @param ColorValueInterface<object> $color */
    public function serialize(ColorValueInterface $color): string
    {
        $inner = $color->getInner();

        if (! $inner instanceof RgbColor) {
            throw new LogicException('Expected RgbColor inside IrisColorValue, got ' . $inner::class);
        }

        return $this->literalSerializer->serialize($inner);
    }

    /**
     * @template TInner of \Bugo\Iris\Contracts\ColorValueInterface
     * @param TInner $inner
     * @return IrisColorValue<TInner>
     */
    private function wrap(\Bugo\Iris\Contracts\ColorValueInterface $inner): IrisColorValue
    {
        return new IrisColorValue($inner);
    }
}
