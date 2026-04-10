<?php

declare(strict_types=1);

namespace Bugo\SCSS\Handlers\Rule;

use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Utils\OutputChunk;

final class RuleCompilationContext
{
    public string $output = '';

    public string $selector = '';

    public string $parentSelector = '';

    public bool $omitOwnRuleOutput = false;

    public bool $requiresRuleBlockOptimization = false;

    public bool $containsStandaloneNestedRuleChunks = false;

    /** @var list<OutputChunk> */
    public array $leadingRootChunks = [];

    /** @var list<OutputChunk> */
    public array $trailingRootChunks = [];

    public bool $hasRenderedChildren = false;

    public function __construct(
        public readonly RuleNode $node,
        public readonly TraversalContext $outerCtx,
        public readonly string $prefix,
        public readonly TraversalContext $childCtx,
    ) {}
}
