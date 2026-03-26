<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Adapters;

use Bugo\Iris\Contracts\ColorValueInterface as IrisValue;
use Bugo\SCSS\Contracts\Color\ColorValueInterface;

/**
 * @template TInner of IrisValue
 * @implements ColorValueInterface<TInner>
 */
final readonly class IrisColorValue implements ColorValueInterface
{
    /** @param TInner $inner */
    public function __construct(public IrisValue $inner) {}

    public function getSpace(): string
    {
        return $this->inner->getSpace();
    }

    public function getChannels(): array
    {
        return $this->inner->getChannels();
    }

    public function getAlpha(): float
    {
        return $this->inner->getAlpha();
    }

    /** @return TInner */
    public function getInner(): IrisValue
    {
        return $this->inner;
    }
}
