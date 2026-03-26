<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

use InvalidArgumentException;

abstract class SassArgumentException extends InvalidArgumentException implements SassThrowable {}
