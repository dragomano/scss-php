<?php

declare(strict_types=1);

namespace Bugo\SCSS\Exceptions;

use RuntimeException;

abstract class SassException extends RuntimeException implements SassThrowable {}
