<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Runtime\Environment;

interface ComparisonListEvaluatorInterface
{
    public function evaluate(ListNode $list, Environment $env): ?AstNode;
}
