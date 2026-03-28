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

interface Visitor
{
    public function visitRoot(RootNode $node, TraversalContext $ctx): string;

    public function visitRule(RuleNode $node, TraversalContext $ctx): string;

    public function visitDeclaration(DeclarationNode $node, TraversalContext $ctx): string;

    public function visitIf(IfNode $node, TraversalContext $ctx): string;

    public function visitFor(ForNode $node, TraversalContext $ctx): string;

    public function visitEach(EachNode $node, TraversalContext $ctx): string;

    public function visitWhile(WhileNode $node, TraversalContext $ctx): string;

    public function visitInclude(IncludeNode $node, TraversalContext $ctx): string;

    public function visitDirective(DirectiveNode $node, TraversalContext $ctx): string;

    public function visitSupports(SupportsNode $node, TraversalContext $ctx): string;

    public function visitUse(UseNode $node, TraversalContext $ctx): string;

    public function visitImport(ImportNode $node, TraversalContext $ctx): string;

    public function visitForward(ForwardNode $node, TraversalContext $ctx): string;

    public function visitAtRoot(AtRootNode $node, TraversalContext $ctx): string;

    public function visitDebug(DebugNode $node, TraversalContext $ctx): string;

    public function visitWarn(WarnNode $node, TraversalContext $ctx): string;

    public function visitError(ErrorNode $node, TraversalContext $ctx): never;

    public function visitComment(CommentNode $node, TraversalContext $ctx): string;

    public function visitVariableDeclaration(VariableDeclarationNode $node, TraversalContext $ctx): string;

    public function visitModuleVarDeclaration(ModuleVarDeclarationNode $node, TraversalContext $ctx): string;

    public function visitMixin(MixinNode $node, TraversalContext $ctx): string;

    public function visitFunction(FunctionDeclarationNode $node, TraversalContext $ctx): string;
}
