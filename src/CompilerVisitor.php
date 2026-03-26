<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\DirectiveNode;
use Bugo\SCSS\Nodes\EachNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\FunctionDeclarationNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Runtime\TraversalContext;

final readonly class CompilerVisitor implements Visitor
{
    public function __construct(private CompilerRuntime $runtime) {}

    public function visitAtRoot(AtRootNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->atRule()->handleAtRoot($node, $ctx);
    }

    public function visitComment(CommentNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->comment()->handle($node, $ctx);
    }

    public function visitDebug(DebugNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->diagnostic()->handleDebug($node, $ctx);
    }

    public function visitDirective(DirectiveNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->atRule()->handleDirective($node, $ctx);
    }

    public function visitDeclaration(DeclarationNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->declaration()->handle($node, $ctx);
    }

    public function visitError(ErrorNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->diagnostic()->handleError($node, $ctx);
    }

    public function visitEach(EachNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->flow()->handleEach($node, $ctx);
    }

    public function visitFor(ForNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->flow()->handleFor($node, $ctx);
    }

    public function visitFunction(FunctionDeclarationNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->definition()->handleFunction($node, $ctx);
    }

    public function visitForward(ForwardNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->moduleLoad()->handleForward($node, $ctx);
    }

    public function visitIf(IfNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->flow()->handleIf($node, $ctx);
    }

    public function visitImport(ImportNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->moduleLoad()->handleImport($node, $ctx);
    }

    public function visitInclude(IncludeNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->block()->handleInclude($node, $ctx);
    }

    public function visitMixin(MixinNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->definition()->handleMixin($node, $ctx);
    }

    public function visitRoot(RootNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->root()->handle($node, $ctx);
    }

    public function visitModuleVarDeclaration(ModuleVarDeclarationNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->definition()->handleModuleVarDeclaration($node, $ctx);
    }

    public function visitRule(RuleNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->block()->handleRule($node, $ctx);
    }

    public function visitVariableDeclaration(VariableDeclarationNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->definition()->handleVariableDeclaration($node, $ctx);
    }

    public function visitWarn(WarnNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->diagnostic()->handleWarn($node, $ctx);
    }

    public function visitWhile(WhileNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->flow()->handleWhile($node, $ctx);
    }

    public function visitUse(UseNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->moduleLoad()->handleUse($node);
    }

    public function visitSupports(SupportsNode $node, TraversalContext $ctx): string
    {
        return $this->runtime->block()->handleSupports($node, $ctx);
    }
}
