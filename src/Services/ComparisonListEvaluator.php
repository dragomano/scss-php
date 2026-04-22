<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Runtime\Environment;

final readonly class ComparisonListEvaluator implements ComparisonListEvaluatorInterface
{
    public function __construct(private Evaluator $evaluator) {}

    public function evaluate(ListNode $list, Environment $env): ?AstNode
    {
        return $this->evaluator->evaluateComparisonList($list, $env);
    }
}
