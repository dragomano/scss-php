<?php

declare(strict_types=1);

namespace Bugo\SCSS\Builtins\Color\Adapters;

use Bugo\Iris\Contracts\ColorValueInterface as IrisValue;

trait IrisColorValueWrap
{
    /**
     * @template TInner of IrisValue
     * @param TInner $inner
     * @return IrisColorValue<TInner>
     */
    private function wrap(IrisValue $inner): IrisColorValue
    {
        return new IrisColorValue($inner);
    }
}
