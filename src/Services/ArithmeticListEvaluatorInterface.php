<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Runtime\Environment;

interface ArithmeticListEvaluatorInterface
{
    public function evaluate(ListNode $list, bool $strict, Environment $env): ?AstNode;
}
