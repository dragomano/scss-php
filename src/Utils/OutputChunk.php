<?php

declare(strict_types=1);

namespace Bugo\SCSS\Utils;

interface OutputChunk
{
    public function content(): string;
}
