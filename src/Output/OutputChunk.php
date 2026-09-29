<?php

declare(strict_types=1);

namespace Bugo\SCSS\Output;

interface OutputChunk
{
    public function content(): string;
}
