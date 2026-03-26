<?php

declare(strict_types=1);

namespace Bugo\SCSS;

use Bugo\SCSS\Handlers\AtRuleNodeHandler;
use Bugo\SCSS\Handlers\BlockNodeHandler;
use Bugo\SCSS\Handlers\CommentNodeHandler;
use Bugo\SCSS\Handlers\DeclarationNodeHandler;
use Bugo\SCSS\Handlers\DefinitionNodeHandler;
use Bugo\SCSS\Handlers\DiagnosticNodeHandler;
use Bugo\SCSS\Handlers\FlowControlNodeHandler;
use Bugo\SCSS\Handlers\ModuleNodeHandler;
use Bugo\SCSS\Handlers\RootNodeHandler;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Runtime\TraversalContext;
use Bugo\SCSS\Services\AstEvaluator;
use Bugo\SCSS\Services\Condition;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Services\Text;
use Bugo\SCSS\Utils\SelectorTokenizer;
use Psr\Log\LoggerInterface;

final class CompilerRuntime
{
    private readonly CompilerDispatcher $dispatcher;

    private ?Context $context = null;

    private ?Condition $condition = null;

    private ?AstEvaluator $ast = null;

    private ?Evaluator $evaluation = null;

    private ?Module $module = null;

    private ?Render $render = null;

    private ?Selector $selector = null;

    private ?Text $text = null;

    private ?CommentNodeHandler $commentHandler = null;

    private ?AtRuleNodeHandler $atRuleHandler = null;

    private ?BlockNodeHandler $blockHandler = null;

    private ?DeclarationNodeHandler $declarationHandler = null;

    private ?DefinitionNodeHandler $definitionHandler = null;

    private ?DiagnosticNodeHandler $diagnosticHandler = null;

    private ?FlowControlNodeHandler $flowControlHandler = null;

    private ?ModuleNodeHandler $moduleLoadHandler = null;

    private ?RootNodeHandler $rootHandler = null;

    public function __construct(
        private readonly CompilerContext $ctx,
        private readonly CompilerOptions $options,
        private readonly LoaderInterface $loader,
        private readonly ParserInterface $parser,
        private readonly LoggerInterface $logger
    ) {
        $this->dispatcher = new CompilerDispatcher();
        $this->dispatcher->setVisitor(new CompilerVisitor($this));
    }

    public function dispatcher(): NodeDispatcherInterface
    {
        return $this->dispatcher;
    }

    public function module(): Module
    {
        if ($this->module instanceof Module) {
            return $this->module;
        }

        $ast = $this->ast ??= new AstEvaluator();

        $this->module = new Module(
            $this->ctx,
            $this->loader,
            $this->parser,
            $ast,
            $this->evaluation(),
            $this->selector(),
            $this->dispatcher
        );

        $ast->setModule($this->module);

        return $this->module;
    }

    public function ast(): AstEvaluator
    {
        $ast = $this->ast ??= new AstEvaluator();

        if (! $this->module instanceof Module) {
            $this->module();
        }

        return $ast;
    }

    public function evaluation(): Evaluator
    {
        return $this->evaluation ??= new Evaluator(
            $this->ctx,
            $this->options,
            $this->parser,
            $this->selector(),
            $this->text(),
            $this->condition(),
            fn(ModuleVarDeclarationNode $node, Environment $env) => $this->module()->assignModuleVariable($node, $env),
            fn(
                string $directive,
                AstNode $messageNode,
                Environment $env,
                ?AstNode $origin = null
            ) => $this->diagnostic()->handleDirective(
                $directive,
                $messageNode,
                new TraversalContext($env, 0),
                $origin
            )
        );
    }

    public function condition(): Condition
    {
        return $this->condition ??= new Condition(
            $this->ctx,
            $this->parser,
            $this->text(),
            fn(AstNode $node, Environment $env): AstNode => $this->evaluation()->evaluateValue($node, $env),
            fn(AstNode $node, Environment $env): string => $this->evaluation()->format($node, $env)
        );
    }

    public function selector(): Selector
    {
        return $this->selector ??= new Selector(
            $this->ctx,
            $this->render(),
            $this->text(),
            new SelectorTokenizer(),
            $this->dispatcher,
            fn(AstNode $node, Environment $env): AstNode => $this->evaluation()->evaluateValue($node, $env),
            fn(ModuleVarDeclarationNode $node, Environment $env) => $this->module()->assignModuleVariable($node, $env),
            fn(AstNode $value): bool => $this->evaluation()->isSassNullValue($value),
            fn(string $property): bool => $this->evaluation()->shouldCompressNamedColorForProperty($property),
            fn(AstNode $value): AstNode => $this->evaluation()->compressNamedColorsForOutput($value),
            fn(AstNode $node, Environment $env): string => $this->evaluation()->format($node, $env)
        );
    }

    public function text(): Text
    {
        return $this->text ??= new Text(
            $this->parser,
            fn(AstNode $node, Environment $env): AstNode => $this->evaluation()->evaluateValue($node, $env),
            fn(AstNode $node, Environment $env): string => $this->evaluation()->format($node, $env)
        );
    }

    public function render(): Render
    {
        return $this->render ??= new Render(
            $this->ctx,
            $this->options,
            fn(AstNode $node, Environment $env): string => $this->evaluation()->format($node, $env)
        );
    }

    public function context(): Context
    {
        return $this->context ??= new Context($this->ctx, $this->options, $this->logger);
    }

    public function comment(): CommentNodeHandler
    {
        return $this->commentHandler ??= new CommentNodeHandler(
            $this->context(),
            $this->evaluation(),
            $this->render()
        );
    }

    public function atRule(): AtRuleNodeHandler
    {
        return $this->atRuleHandler ??= new AtRuleNodeHandler(
            $this->dispatcher,
            $this->evaluation(),
            $this->render(),
            $this->selector()
        );
    }

    public function block(): BlockNodeHandler
    {
        return $this->blockHandler ??= new BlockNodeHandler(
            $this->dispatcher,
            $this->context(),
            $this->evaluation(),
            $this->ctx->functionRegistry,
            $this->module(),
            $this->render(),
            $this->selector()
        );
    }

    public function declaration(): DeclarationNodeHandler
    {
        return $this->declarationHandler ??= new DeclarationNodeHandler(
            $this->evaluation(),
            $this->render(),
            $this->text()
        );
    }

    public function definition(): DefinitionNodeHandler
    {
        return $this->definitionHandler ??= new DefinitionNodeHandler(
            $this->evaluation(),
            $this->module(),
            $this->context()
        );
    }

    public function diagnostic(): DiagnosticNodeHandler
    {
        return $this->diagnosticHandler ??= new DiagnosticNodeHandler(
            $this->context(),
            $this->evaluation(),
            $this->render()
        );
    }

    public function flow(): FlowControlNodeHandler
    {
        return $this->flowControlHandler ??= new FlowControlNodeHandler(
            $this->dispatcher,
            $this->evaluation(),
            $this->render()
        );
    }

    public function moduleLoad(): ModuleNodeHandler
    {
        return $this->moduleLoadHandler ??= new ModuleNodeHandler(
            $this->evaluation(),
            $this->module(),
            $this->render(),
            $this->selector()
        );
    }

    public function root(): RootNodeHandler
    {
        return $this->rootHandler ??= new RootNodeHandler($this->dispatcher, $this->render());
    }
}
