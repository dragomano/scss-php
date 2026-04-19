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
use Bugo\SCSS\Services\ClosureAstValueEvaluator;
use Bugo\SCSS\Services\ClosureAstValueFormatter;
use Bugo\SCSS\Services\ClosureDiagnosticDirectiveHandler;
use Bugo\SCSS\Services\ClosureEachLoopBinder;
use Bugo\SCSS\Services\ClosureFunctionConditionEvaluator;
use Bugo\SCSS\Services\ClosureModuleVariableAssigner;
use Bugo\SCSS\Services\ClosureVariableDeclarationApplier;
use Bugo\SCSS\Services\Condition;
use Bugo\SCSS\Services\Context;
use Bugo\SCSS\Services\Evaluator;
use Bugo\SCSS\Services\ExtendsResolver;
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
            new ClosureFunctionConditionEvaluator(
                fn(string $condition, Environment $env): bool => $this->evaluation()->evaluateFunctionCondition($condition, $env),
            ),
            new ClosureVariableDeclarationApplier(
                fn(AstNode $node, Environment $env): bool => $this->evaluation()->applyVariableDeclaration($node, $env),
            ),
            new ClosureEachLoopBinder(
                fn(AstNode $value): array => $this->evaluation()->eachIterableItems($value),
                fn(array $variables, AstNode $item, Environment $env) => $this->evaluation()->assignEachVariables($variables, $item, $env),
            ),
            $this->createAstValueFormatter(),
        );
    }

    private function createSelector(): Selector
    {
        return new Selector(
            $this->ctx,
            $this->render(),
            $this->text(),
            $this->selectorTokenizer(),
            $this->dispatcher,
            $this->extends(),
            $this->createAstValueEvaluator(),
            $this->createModuleVariableAssigner(),
            fn(AstNode $value): bool => $this->evaluation()->isSassNullValue($value),
            fn(string $property): bool => $this->evaluation()->shouldCompressNamedColorForProperty($property),
            fn(AstNode $value): AstNode => $this->evaluation()->compressNamedColorsForOutput($value),
            $this->createAstValueFormatter(),
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

    private function createModuleVariableAssigner(): ClosureModuleVariableAssigner
    {
        return new ClosureModuleVariableAssigner(
            fn(ModuleVarDeclarationNode $node, Environment $env) => $this->module()->assignModuleVariable($node, $env),
        );
    }

    private function createDiagnosticDirectiveHandler(): ClosureDiagnosticDirectiveHandler
    {
        return new ClosureDiagnosticDirectiveHandler(
            fn(
                string $directive,
                AstNode $messageNode,
                Environment $env,
                ?AstNode $origin = null,
            ) => $this->diagnostic()->handleDirective(
                $directive,
                $messageNode,
                new TraversalContext($env, 0),
                $origin,
            ),
        );
    }

    private function createAstValueEvaluator(): ClosureAstValueEvaluator
    {
        return new ClosureAstValueEvaluator(
            fn(AstNode $node, Environment $env): AstNode => $this->evaluation()->evaluateValue($node, $env),
        );
    }

    private function createAstValueFormatter(): ClosureAstValueFormatter
    {
        return new ClosureAstValueFormatter(
            fn(AstNode $node, Environment $env): string => $this->evaluation()->format($node, $env),
        );
    }
}
