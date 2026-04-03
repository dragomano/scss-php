<?php

declare(strict_types=1);

use Bugo\SCSS\Lexer\Token;
use Bugo\SCSS\Lexer\TokenStream;
use Bugo\SCSS\Lexer\TokenType;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\ModuleVarDeclarationNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Parser;
use Bugo\SCSS\Parser\RuleParser;

function ruleParserTestToken(
    TokenType $type,
    string $value = '',
    int $line = 1,
    int $column = 1,
): Token {
    return new Token($type, $value, $line, $column);
}

/**
 * @param array<int, Token> $tokens
 * @param array<string, mixed> $overrides
 * @return array{0: RuleParser, 1: TokenStream}
 */
function createRuleParserForTest(array $tokens, array $overrides = []): array
{
    $stream = new TokenStream($tokens);

    $parseValue          = $overrides['parseValue'] ?? static fn(): AstNode => new StringNode('value');
    $parseValueModifiers = $overrides['parseValueModifiers'] ?? static fn(): array => [
        'global'    => false,
        'default'   => false,
        'important' => false,
    ];
    $parseCustomPropertyValue = $overrides['parseCustomPropertyValue'] ?? static fn(): string => 'custom-value';
    $isInsideBraces           = $overrides['isInsideBraces'] ?? static fn(): bool => false;
    $parseRuleFromSelector    = $overrides['parseRuleFromSelector']
        ?? static fn(string $selector, int $line, int $column): RuleNode => new RuleNode(
            $selector,
            [],
            $line,
            $column,
        );

    return [
        new RuleParser(
            $stream,
            $parseValue,
            $parseValueModifiers,
            $parseCustomPropertyValue,
            $isInsideBraces,
            $parseRuleFromSelector,
        ),
        $stream,
    ];
}

describe('RuleParser', function () {
    beforeEach(function () {
        $this->parser = new Parser();
    });

    describe('variable declarations', function () {
        it('parses simple variable declaration', function () {
            $ast  = $this->parser->parse('$color: red;');
            $node = $ast->children[0];

            /** @var VariableDeclarationNode $node */
            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('color')
                ->and($node->value)->toBeInstanceOf(StringNode::class)
                ->and($node->global)->toBeFalse()
                ->and($node->default)->toBeFalse();
        });

        it('parses variable declaration with !default', function () {
            $ast  = $this->parser->parse('$primary: blue !default;');
            $node = $ast->children[0];

            /** @var VariableDeclarationNode $node */
            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('primary')
                ->and($node->default)->toBeTrue()
                ->and($node->global)->toBeFalse();
        });

        it('parses variable declaration with !global', function () {
            $ast  = $this->parser->parse('.a { $x: 10 !global; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $node = $rule->children[0];

            /** @var VariableDeclarationNode $node */
            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('x')
                ->and($node->global)->toBeTrue();
        });

        it('parses variable declaration with numeric value', function () {
            $ast  = $this->parser->parse('$size: 16px;');
            $node = $ast->children[0];

            /** @var VariableDeclarationNode $node */
            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('size')
                ->and($node->value)->toBeInstanceOf(NumberNode::class);

            /** @var NumberNode $value */
            $value = $node->value;

            expect($value->value)->toBe(16)
                ->and($value->unit)->toBe('px');
        });

        it('parses multiple variable declarations', function () {
            $ast    = $this->parser->parse('$a: 1; $b: 2; $c: 3;');
            $first  = $ast->children[0];
            $second = $ast->children[1];
            $third  = $ast->children[2];

            /** @var VariableDeclarationNode $first */
            /** @var VariableDeclarationNode $second */
            /** @var VariableDeclarationNode $third */
            expect(count($ast->children))->toBe(3)
                ->and($first->name)->toBe('a')
                ->and($second->name)->toBe('b')
                ->and($third->name)->toBe('c');
        });

        it('returns null without a leading dollar sign', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, 'color'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            expect($ruleParser->parseVariableDeclaration())->toBeNull();
        });

        it('returns null when a variable declaration has no colon', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::DOLLAR, '$'),
                ruleParserTestToken(TokenType::IDENTIFIER, 'color'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            expect($ruleParser->parseVariableDeclaration())->toBeNull();
        });

        it('parses a variable declaration even when the identifier is empty', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::DOLLAR, '$'),
                ruleParserTestToken(TokenType::COLON, ':'),
                ruleParserTestToken(TokenType::IDENTIFIER, 'red'),
                ruleParserTestToken(TokenType::SEMICOLON, ';'),
                ruleParserTestToken(TokenType::EOF),
            ], [
                'parseValue' => static fn(): AstNode => new StringNode('red'),
            ]);

            $node = $ruleParser->parseVariableDeclaration();

            /** @var VariableDeclarationNode $node */
            expect($node)->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($node->name)->toBe('');
        });
    });

    describe('CSS declarations', function () {
        it('parses simple CSS declaration', function () {
            $ast  = $this->parser->parse('.a { color: red; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $decl = $rule->children[0];

            /** @var DeclarationNode $decl */
            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('color')
                ->and($decl->important)->toBeFalse();
        });

        it('parses declaration with vendor prefix', function () {
            $ast  = $this->parser->parse('.a { -webkit-transform: rotate(45deg); }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $decl = $rule->children[0];

            /** @var DeclarationNode $decl */
            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('-webkit-transform');
        });

        it('parses declaration with !important (stored in value at parse time)', function () {
            $ast  = $this->parser->parse('.a { color: red !important; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $decl = $rule->children[0];

            /** @var DeclarationNode $decl */
            expect($decl)->toBeInstanceOf(DeclarationNode::class);

            /** @var ListNode $value */
            $value = $decl->value;

            $lastItem = end($value->items);

            /** @var StringNode $lastItem */
            expect($lastItem)->toBeInstanceOf(StringNode::class)
                ->and($lastItem->value)->toBe('!important');
        });

        it('parses multiple declarations in one rule', function () {
            $ast  = $this->parser->parse('.a { color: red; margin: 0; padding: 0; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $children = $rule->children;
            $first    = $children[0];
            $second   = $children[1];
            $third    = $children[2];

            /** @var DeclarationNode $first */
            /** @var DeclarationNode $second */
            /** @var DeclarationNode $third */
            expect(count($children))->toBe(3)
                ->and($first->property)->toBe('color')
                ->and($second->property)->toBe('margin')
                ->and($third->property)->toBe('padding');
        });

        it('routes custom properties through the custom property value parser', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::COLON, ':'),
                ruleParserTestToken(TokenType::IDENTIFIER, 'red'),
                ruleParserTestToken(TokenType::SEMICOLON, ';'),
                ruleParserTestToken(TokenType::EOF),
            ], [
                'parseCustomPropertyValue' => static fn(): string => 'raw-accent',
            ]);

            $node = $ruleParser->parseDeclarationFromProperty('--accent', 3, 7);

            /** @var DeclarationNode $node */
            expect($node)->toBeInstanceOf(DeclarationNode::class)
                ->and($node->property)->toBe('--accent')
                ->and($node->value)->toBeInstanceOf(StringNode::class)
                ->and($node->line)->toBe(3)
                ->and($node->column)->toBe(7);

            /** @var StringNode $value */
            $value = $node->value;

            expect($value->value)->toBe('raw-accent');
        });
    });

    describe('CSS rules', function () {
        it('parses class selector rule', function () {
            $ast  = $this->parser->parse('.button { display: flex; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('.button');
        });

        it('parses ID selector rule', function () {
            $ast  = $this->parser->parse('#header { background: blue; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('#header');
        });

        it('parses element selector rule', function () {
            $ast  = $this->parser->parse('body { margin: 0; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('body');
        });

        it('parses compound selector', function () {
            $ast  = $this->parser->parse('.btn.primary:hover { color: white; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('.btn.primary:hover');
        });

        it('parses selector list (comma-separated)', function () {
            $ast  = $this->parser->parse('h1, h2, h3 { font-weight: bold; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toContain('h1')
                ->and($rule->selector)->toContain('h2')
                ->and($rule->selector)->toContain('h3');
        });

        it('parses descendant combinator', function () {
            $ast  = $this->parser->parse('.parent .child { color: red; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('.parent .child');
        });

        it('parses child combinator >', function () {
            $ast  = $this->parser->parse('.parent > .child { color: red; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toContain('>');
        });

        it('parses nested rules', function () {
            $ast    = $this->parser->parse('.parent { color: red; .child { color: blue; } }');
            $parent = $ast->children[0];

            /** @var RuleNode $parent */
            $child = $parent->children[1];

            /** @var RuleNode $child */
            expect($parent)->toBeInstanceOf(RuleNode::class)
                ->and($parent->selector)->toBe('.parent')
                ->and($child)->toBeInstanceOf(RuleNode::class)
                ->and($child->selector)->toBe('.child');
        });

        it('parses & parent reference in nested rule', function () {
            $ast  = $this->parser->parse('.btn { &:hover { color: red; } }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $nested = $rule->children[0];

            /** @var RuleNode $nested */
            expect($nested)->toBeInstanceOf(RuleNode::class)
                ->and($nested->selector)->toBe('&:hover');
        });

        it('parses pseudo-class selectors', function () {
            $ast  = $this->parser->parse('a:hover { color: red; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('a:hover');
        });

        it('parses pseudo-element selectors', function () {
            $ast  = $this->parser->parse('p::before { content: ""; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('p::before');
        });

        it('parses attribute selectors', function () {
            $ast  = $this->parser->parse('input[type="text"] { border: none; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toContain('[type=');
        });

        it('parses top-level likely selectors followed by a block after a colon', function () {
            $ast  = $this->parser->parse('a: { color: red; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('a:');
        });

        it('parses top-level non-selector colon syntax with a block as a rule', function () {
            $ast  = $this->parser->parse('border-color: { color: red; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('border-color:');
        });

        it('parses top-level non-selector colon syntax ending at eof as a declaration', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, 'border-color'),
                ruleParserTestToken(TokenType::COLON, ':'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            $node = $ruleParser->parseRuleOrDeclaration();

            /** @var DeclarationNode $node */
            expect($node)->toBeInstanceOf(DeclarationNode::class)
                ->and($node->property)->toBe('border-color');
        });

        it('keeps hash tokens when parseRule is called directly', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::HASH, 'header'),
                ruleParserTestToken(TokenType::WHITESPACE, ' '),
                ruleParserTestToken(TokenType::LBRACE, '{'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            $node = $ruleParser->parseRule();

            /** @var RuleNode $node */
            expect($node)->toBeInstanceOf(RuleNode::class)
                ->and($node->selector)->toBe('#header');
        });

        it('preserves double-colon pseudo-elements in selector parsing', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, 'p'),
                ruleParserTestToken(TokenType::COLON, ':'),
                ruleParserTestToken(TokenType::COLON, ':'),
                ruleParserTestToken(TokenType::IDENTIFIER, 'before'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            expect($ruleParser->parseSelectorOrProperty())->toBe('p::before');
        });

        it('stops selector parsing before a trailing comment token', function () {
            [$ruleParser, $stream] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, 'color'),
                ruleParserTestToken(TokenType::COMMENT_SILENT, '// note'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            expect($ruleParser->parseSelectorOrProperty())->toBe('color')
                ->and($stream->current()->type)->toBe(TokenType::COMMENT_SILENT);
        });
    });

    describe('custom properties', function () {
        it('parses CSS custom property declaration', function () {
            $ast  = $this->parser->parse('.a { --primary: #ff0000; }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $decl = $rule->children[0];

            /** @var DeclarationNode $decl */

            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--primary');
        });

        it('parses custom property with fallback value', function () {
            $ast  = $this->parser->parse('.a { --shadow: 0 2px 4px rgba(0,0,0,.5); }');
            $rule = $ast->children[0];

            /** @var RuleNode $rule */
            $decl = $rule->children[0];

            /** @var DeclarationNode $decl */
            expect($decl)->toBeInstanceOf(DeclarationNode::class)
                ->and($decl->property)->toBe('--shadow');
        });

        it('treats dashed properties inside blocks as declarations', function () {
            [$ruleParser] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, '--accent'),
                ruleParserTestToken(TokenType::COLON, ':'),
                ruleParserTestToken(TokenType::IDENTIFIER, 'red'),
                ruleParserTestToken(TokenType::SEMICOLON, ';'),
                ruleParserTestToken(TokenType::EOF),
            ], [
                'isInsideBraces' => static fn(): bool => true,
                'parseCustomPropertyValue' => static fn(): string => 'raw-accent',
            ]);

            $node = $ruleParser->parseRuleOrDeclaration();

            /** @var DeclarationNode $node */
            expect($node)->toBeInstanceOf(DeclarationNode::class)
                ->and($node->property)->toBe('--accent')
                ->and($node->value)->toBeInstanceOf(StringNode::class);

            /** @var StringNode $value */
            $value = $node->value;

            expect($value->value)->toBe('raw-accent');
        });

        it('parses css variable tokens without an embedded colon as empty declarations', function () {
            [$ruleParser, $stream] = createRuleParserForTest([
                ruleParserTestToken(TokenType::CSS_VARIABLE, '--orphan', 2, 4),
                ruleParserTestToken(TokenType::EOF),
            ], [
                'isInsideBraces' => static fn(): bool => true,
            ]);

            $node = $ruleParser->parseRuleOrDeclaration();

            /** @var DeclarationNode $node */
            expect($node)->toBeInstanceOf(DeclarationNode::class)
                ->and($node->property)->toBe('--orphan')
                ->and($node->value)->toBeInstanceOf(StringNode::class)
                ->and($stream->getPosition())->toBe(1);

            /** @var StringNode $value */
            $value = $node->value;

            expect($value->value)->toBe('');
        });
    });

    describe('module variable declarations', function () {
        it('parses module variable reassignment', function () {
            $ast  = $this->parser->parse('@use "theme"; theme.$color: blue;');
            $node = $ast->children[1];

            /** @var ModuleVarDeclarationNode $node */
            expect($node)->toBeInstanceOf(ModuleVarDeclarationNode::class)
                ->and($node->module)->toBe('theme')
                ->and($node->name)->toBe('color');
        });

        it('restores the token stream when a module variable declaration has no colon', function () {
            [$ruleParser, $stream] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, 'theme'),
                ruleParserTestToken(TokenType::DOT, '.'),
                ruleParserTestToken(TokenType::DOLLAR, '$'),
                ruleParserTestToken(TokenType::IDENTIFIER, 'color'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            expect($ruleParser->parseModuleVarDeclaration())->toBeNull()
                ->and($stream->getPosition())->toBe(0);
        });
    });

    describe('public helper methods', function () {
        it('returns false from hasRuleBlockAfterColon and restores the position at eof', function () {
            [$ruleParser, $stream] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, 'value'),
                ruleParserTestToken(TokenType::EOF),
            ]);

            expect($ruleParser->hasRuleBlockAfterColon())->toBeFalse()
                ->and($stream->getPosition())->toBe(0);
        });

        it('returns false from hasRuleBlockAfterColon when the stream ends without eof', function () {
            [$ruleParser, $stream] = createRuleParserForTest([
                ruleParserTestToken(TokenType::IDENTIFIER, 'value'),
            ]);

            expect($ruleParser->hasRuleBlockAfterColon())->toBeFalse()
                ->and($stream->getPosition())->toBe(0);
        });
    });
});
