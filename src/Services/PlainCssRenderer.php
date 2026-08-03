<?php

declare(strict_types=1);

namespace Bugo\SCSS\Services;

use Bugo\SCSS\NodeDispatcherInterface;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\Visitable;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;

use function in_array;
use function strtolower;

final readonly class PlainCssRenderer
{
    public function __construct(
        private NodeDispatcherInterface $dispatcher,
        private Render $render,
    ) {}

    public function render(RootNode $root, Environment $env): string
    {
        $out = '';

        foreach ($root->children as $child) {
            $compiled = $this->renderChild($child, $env, 0, true);

            if ($compiled === '') {
                continue;
            }

            if ($out !== '') {
                $out .= "\n";
            }

            $out .= $compiled;
        }

        return $out;
    }

    private function renderChild(AstNode $child, Environment $env, int $indent, bool $isTopLevel = false): string
    {
        if ($child instanceof RuleNode) {
            return $this->renderCssRule($child, $env, $indent, $isTopLevel);
        }

        if ($child instanceof SupportsNode) {
            return $this->renderCssDirective($child, $env, $indent);
        }

        if ($child instanceof DirectiveNode && $child->hasBlock) {
            return $this->renderCssDirective($child, $env, $indent);
        }

        if (! $child instanceof Visitable) {
            return '';
        }

        return $this->dispatcher->compileWithContext($child, new TraversalContext($env, $indent));
    }

    /**
     * @param array<AstNode> $children
     */
    private function renderChildren(array $children, Environment $env, int $indent): string
    {
        $out = '';

        foreach ($children as $child) {
            $compiled = $this->renderChild($child, $env, $indent);

            if ($compiled === '') {
                continue;
            }

            if ($out !== '') {
                $out .= "\n";
            }

            $out .= $compiled;
        }

        return $out;
    }

    private function renderCssRule(RuleNode $node, Environment $env, int $indent, bool $isTopLevel): string
    {
        $prefix   = $this->render->indentPrefix($indent);
        $bubbling = [];
        $regular  = [];

        foreach ($node->children as $child) {
            if ($isTopLevel && $this->isBubblingAtRule($child)) {
                $bubbling[] = $child;
            } else {
                $regular[] = $child;
            }
        }

        $out     = '';
        $content = $this->renderChildren($regular, $env, $indent + 1);

        if ($content !== '') {
            $out .= $prefix . $node->selector . " {\n" . $content . "\n" . $prefix . '}';
        }

        foreach ($bubbling as $atRule) {
            if (! $atRule instanceof SupportsNode && ! $atRule instanceof DirectiveNode) {
                continue;
            }

            if ($out !== '') {
                $out .= "\n";
            }

            $innerPrefix = $this->render->indentPrefix($indent + 1);

            $innerRule = $innerPrefix . $node->selector . " {\n"
                . $this->renderChildren($atRule->body, $env, $indent + 2) . "\n"
                . $innerPrefix . '}';

            $out .= $prefix . $this->atRuleHeader($atRule) . " {\n" . $innerRule . "\n" . $prefix . '}';
        }

        return $out;
    }

    private function renderCssDirective(SupportsNode|DirectiveNode $node, Environment $env, int $indent): string
    {
        $prefix  = $this->render->indentPrefix($indent);
        $content = $this->renderChildren($node->body, $env, $indent + 1);

        if ($content === '') {
            return $prefix . $this->atRuleHeader($node) . ' {}';
        }

        return $prefix . $this->atRuleHeader($node) . " {\n" . $content . "\n" . $prefix . '}';
    }

    private function isBubblingAtRule(AstNode $node): bool
    {
        if ($node instanceof SupportsNode) {
            return true;
        }

        if (! $node instanceof DirectiveNode || ! $node->hasBlock) {
            return false;
        }

        return ! in_array(strtolower($node->name), ['keyframes', 'font-face', 'layer', 'charset'], true);
    }

    private function atRuleHeader(SupportsNode|DirectiveNode $node): string
    {
        if ($node instanceof SupportsNode) {
            return '@supports ' . $node->condition;
        }

        $prelude = $node->prelude === '' ? '' : ' ' . $node->prelude;

        return '@' . $node->name . $prelude;
    }
}
