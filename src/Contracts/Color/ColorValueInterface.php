<?php

declare(strict_types=1);

namespace Bugo\SCSS\Contracts\Color;

/** @template-covariant TInner of object */
interface ColorValueInterface
{
    public function getSpace(): string;

    /** @return list<float|null> */
    public function getChannels(): array;

    public function getAlpha(): float;

    /** @return TInner */
    public function getInner(): object;
}
