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
use Bugo\SCSS\Runtime\Environment;
use Bugo\SCSS\Services\AstValueEvaluatorInterface;
use Bugo\SCSS\Services\AstValueFormatterInterface;
use Bugo\SCSS\Services\Condition;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\CssArgumentEvaluator;
use Bugo\SCSS\Services\DiagnosticDirectiveHandler;
use Bugo\SCSS\Services\DiagnosticDirectiveHandlerInterface;
use Bugo\SCSS\Services\EachLoopBinder;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\ExtendsResolver;
use Bugo\SCSS\Services\FunctionConditionEvaluator;
use Bugo\SCSS\Services\Module;
use Bugo\SCSS\Services\ModuleVariableAssigner;
use Bugo\SCSS\Services\ModuleVariableAssignerInterface;
use Bugo\SCSS\Services\Render;
use Bugo\SCSS\Services\RuntimeAstValueEvaluator;
use Bugo\SCSS\Services\RuntimeAstValueFormatter;
use Bugo\SCSS\Services\RuntimeCalculationArgumentNormalizer;
use Bugo\SCSS\Services\Selector;
use Bugo\SCSS\Services\Text;
use Bugo\SCSS\Services\VariableDeclarationApplier;
use Bugo\SCSS\Utils\SelectorTokenizer;
use Psr\Log\LoggerInterface;

final class CompilerRuntime
{
    private readonly CompilerDispatcher $dispatcher;

    private ?Context $context = null;

    private ?Condition $condition = null;

    private ?CssArgumentEvaluator $cssArgumentEvaluator = null;

    private ?Evaluator $evaluation = null;

    private ?ExtendsResolver $extends = null;

    private ?Module $module = null;

    private ?Render $render = null;

    private ?Selector $selector = null;

    private ?SelectorTokenizer $selectorTokenizer = null;

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
        private readonly LoggerInterface $logger,
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
        return $this->module ??= $this->createModule();
    }

    public function evaluation(): Evaluator
    {
        return $this->evaluation ??= $this->createEvaluator();
    }

    public function condition(): Condition
    {
        return $this->condition ??= $this->createCondition();
    }

    public function cssArgumentEvaluator(): CssArgumentEvaluator
    {
        return $this->cssArgumentEvaluator ??= $this->createCssArgumentEvaluator();
    }

    public function extends(): ExtendsResolver
    {
        return $this->extends ??= $this->createExtendsResolver();
    }

    public function selector(): Selector
    {
        return $this->selector ??= $this->createSelector();
    }

    private function selectorTokenizer(): SelectorTokenizer
    {
        return $this->selectorTokenizer ??= new SelectorTokenizer();
    }

    public function text(): Text
    {
        return $this->text ??= $this->createText();
    }

    public function render(): Render
    {
        return $this->render ??= $this->createRender();
    }

    public function context(): Context
    {
        return $this->context ??= new Context($this->ctx, $this->options, $this->logger);
    }

    public function comment(): CommentNodeHandler
    {
        return $this->commentHandler ??= $this->createCommentHandler();
    }

    public function atRule(): AtRuleNodeHandler
    {
        return $this->atRuleHandler ??= $this->createAtRuleHandler();
    }

    public function block(): BlockNodeHandler
    {
        return $this->blockHandler ??= $this->createBlockHandler();
    }

    public function declaration(): DeclarationNodeHandler
    {
        return $this->declarationHandler ??= $this->createDeclarationHandler();
    }

    public function definition(): DefinitionNodeHandler
    {
        return $this->definitionHandler ??= $this->createDefinitionHandler();
    }

    public function diagnostic(): DiagnosticNodeHandler
    {
        return $this->diagnosticHandler ??= $this->createDiagnosticHandler();
    }

    public function flow(): FlowControlNodeHandler
    {
        return $this->flowControlHandler ??= $this->createFlowHandler();
    }

    public function moduleLoad(): ModuleNodeHandler
    {
        return $this->moduleLoadHandler ??= $this->createModuleNodeHandler();
    }

    public function root(): RootNodeHandler
    {
        return $this->rootHandler ??= $this->createRootHandler();
    }

    private function createModule(): Module
    {
        return new Module(
            $this->ctx,
            $this->loader,
            $this->parser,
            $this->evaluation(),
            $this->selector(),
            $this->dispatcher,
        );
    }

    private function createEvaluator(): Evaluator
    {
        return new Evaluator(
            $this->ctx,
            $this->options,
            $this->parser,
            $this->selector(),
            $this->text(),
            $this->condition(),
            $this->createModuleVariableAssigner(),
            $this->createDiagnosticDirectiveHandler(),
        );
    }

    private function createCondition(): Condition
    {
        return new Condition(
            $this->ctx,
            $this->parser,
            $this->text(),
            $this->createAstValueEvaluator(),
            $this->createAstValueFormatter(),
        );
    }

    private function createExtendsResolver(): ExtendsResolver
    {
        return new ExtendsResolver(
            $this->ctx,
            $this->text(),
            $this->selectorTokenizer(),
            $this->createAstValueEvaluator(),
            new FunctionConditionEvaluator($this->condition()),
            new VariableDeclarationApplier(
                $this->createModuleVariableAssigner(),
                new class ($this) implements AstValueEvaluatorInterface {
                    public function __construct(private readonly CompilerRuntime $runtime) {}

                    public function evaluate(AstNode $node, Environment $env): AstNode
                    {
                        return $this->runtime->evaluation()->evaluateValueWithSlashDivision($node, $env);
                    }
                },
            ),
            new EachLoopBinder($this->ctx->valueFactory),
            $this->createAstValueFormatter(),
        );
    }

    private function createSelector(): Selector
    {
        return new Selector(
            $this->ctx,
            $this->options,
            $this->render(),
            $this->text(),
            $this->selectorTokenizer(),
            $this->dispatcher,
            $this->extends(),
            $this->createModuleVariableAssigner(),
            $this->cssArgumentEvaluator(),
            $this->createAstValueEvaluator(),
            $this->createAstValueFormatter(),
        );
    }

    private function createCssArgumentEvaluator(): CssArgumentEvaluator
    {
        return new CssArgumentEvaluator(
            $this->createAstValueEvaluator(),
            new RuntimeCalculationArgumentNormalizer($this),
        );
    }

    private function createText(): Text
    {
        return new Text(
            $this->parser,
            $this->createAstValueEvaluator(),
            $this->createAstValueFormatter(),
        );
    }

    private function createRender(): Render
    {
        return new Render(
            $this->ctx,
            $this->options,
            $this->createAstValueFormatter(),
        );
    }

    private function createCommentHandler(): CommentNodeHandler
    {
        return new CommentNodeHandler(
            $this->context(),
            $this->evaluation(),
            $this->render(),
        );
    }

    private function createAtRuleHandler(): AtRuleNodeHandler
    {
        return new AtRuleNodeHandler(
            $this->dispatcher,
            $this->evaluation(),
            $this->render(),
            $this->selector(),
        );
    }

    private function createBlockHandler(): BlockNodeHandler
    {
        return new BlockNodeHandler(
            $this->dispatcher,
            $this->context(),
            $this->evaluation(),
            $this->ctx->functionRegistry,
            $this->module(),
            $this->render(),
            $this->selector(),
        );
    }

    private function createDeclarationHandler(): DeclarationNodeHandler
    {
        return new DeclarationNodeHandler(
            $this->evaluation(),
            $this->render(),
            $this->text(),
        );
    }

    private function createDefinitionHandler(): DefinitionNodeHandler
    {
        return new DefinitionNodeHandler(
            $this->evaluation(),
            $this->module(),
            $this->context(),
        );
    }

    private function createDiagnosticHandler(): DiagnosticNodeHandler
    {
        return new DiagnosticNodeHandler(
            $this->context(),
            $this->evaluation(),
            $this->render(),
        );
    }

    private function createFlowHandler(): FlowControlNodeHandler
    {
        return new FlowControlNodeHandler(
            $this->dispatcher,
            $this->evaluation(),
            $this->render(),
        );
    }

    private function createModuleNodeHandler(): ModuleNodeHandler
    {
        return new ModuleNodeHandler(
            $this->evaluation(),
            $this->module(),
            $this->render(),
            $this->selector(),
        );
    }

    private function createRootHandler(): RootNodeHandler
    {
        return new RootNodeHandler($this->dispatcher, $this->render());
    }

    private function createModuleVariableAssigner(): ModuleVariableAssignerInterface
    {
        return new ModuleVariableAssigner($this);
    }

    private function createDiagnosticDirectiveHandler(): DiagnosticDirectiveHandlerInterface
    {
        return new DiagnosticDirectiveHandler($this);
    }

    private function createAstValueEvaluator(): AstValueEvaluatorInterface
    {
        return new RuntimeAstValueEvaluator($this);
    }

    private function createAstValueFormatter(): AstValueFormatterInterface
    {
        return new RuntimeAstValueFormatter($this);
    }
}
