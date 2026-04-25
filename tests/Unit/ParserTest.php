<?php

declare(strict_types=1);

use Bugo\SCSS\Nodes\ArgumentNode;
use Bugo\SCSS\Nodes\AtRootNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\CommentNode;
use Bugo\SCSS\Nodes\DebugNode;
use Bugo\SCSS\Nodes\DeclarationNode;
use Bugo\SCSS\Nodes\ErrorNode;
use Bugo\SCSS\Nodes\ExtendNode;
use Bugo\SCSS\Nodes\ForNode;
use Bugo\SCSS\Nodes\ForwardNode;
use Bugo\SCSS\Nodes\FunctionDeclarationNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\IfNode;
use Bugo\SCSS\Nodes\ImportNode;
use Bugo\SCSS\Nodes\IncludeNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinNode;
use Bugo\SCSS\Nodes\NamedArgumentNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;
use Bugo\SCSS\Nodes\ReturnNode;
use Bugo\SCSS\Nodes\RootNode;
use Bugo\SCSS\Nodes\RuleNode;
use Bugo\SCSS\Nodes\SpreadArgumentNode;
use Bugo\SCSS\Nodes\StringNode;
use Bugo\SCSS\Nodes\SupportsNode;
use Bugo\SCSS\Nodes\UseNode;
use Bugo\SCSS\Nodes\VariableDeclarationNode;
use Bugo\SCSS\Nodes\VariableReferenceNode;
use Bugo\SCSS\Nodes\WarnNode;
use Bugo\SCSS\Nodes\WhileNode;
use Bugo\SCSS\Parser;

describe('Parser', function () {
    beforeEach(function () {
        $this->parser = new Parser();
    });

    describe('parse()', function () {
        it('parses simple CSS rules', function () {
            $source = '.test { color: red; }';

            $ast = $this->parser->parse($source);

            expect($ast)->toBeInstanceOf(RootNode::class)
                ->and(count($ast->children))->toBe(1);

            $rule = $ast->children[0];
            expect($rule)->toBeInstanceOf(RuleNode::class)
                ->and($rule->selector)->toBe('.test')
                ->and(count($rule->children))->toBe(1);

            $declaration = $rule->children[0];
            expect($declaration)->toBeInstanceOf(DeclarationNode::class)
                ->and($declaration->property)->toBe('color')
                ->and($declaration->value)->toBeInstanceOf(StringNode::class)
                ->and($declaration->value->value)->toBe('red');
        });

        it('parses bare null and boolean literals into dedicated nodes', function () {
            $source = <<<'SCSS'
            $empty: null;
            .test {
              enabled: true;
              disabled: false;
            }
            SCSS;

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($ast->children[0]->value)->toBeInstanceOf(NullNode::class)
                ->and($ast->children[1])->toBeInstanceOf(RuleNode::class)
                ->and($ast->children[1]->children[0])->toBeInstanceOf(DeclarationNode::class)
                ->and($ast->children[1]->children[0]->value)->toBeInstanceOf(BooleanNode::class)
                ->and($ast->children[1]->children[0]->value->value)->toBeTrue()
                ->and($ast->children[1]->children[1])->toBeInstanceOf(DeclarationNode::class)
                ->and($ast->children[1]->children[1]->value)->toBeInstanceOf(BooleanNode::class)
                ->and($ast->children[1]->children[1]->value->value)->toBeFalse();
        });

        it('parses nested rules', function () {
            $source = '.parent { .child { margin: 10px; } }';

            $ast = $this->parser->parse($source);

            $parentRule = $ast->children[0];
            expect($parentRule->selector)->toBe('.parent');

            $childRule = $parentRule->children[0];
            expect($childRule)->toBeInstanceOf(RuleNode::class)
                ->and($childRule->selector)->toBe('.child');

            $declaration = $childRule->children[0];
            expect($declaration->property)->toBe('margin')
                ->and($declaration->value)->toBeInstanceOf(NumberNode::class)
                ->and($declaration->value->value)->toBe(10)
                ->and($declaration->value->unit)->toBe('px');
        });

        it('parses color values', function () {
            $source = '.colors { hex3: #f00; hex6: #ff0000; }';

            $ast = $this->parser->parse($source);

            $declarations = $ast->children[0]->children;

            expect($declarations[0]->value)->toBeInstanceOf(ColorNode::class)
                ->and($declarations[0]->value->value)->toBe('#f00')
                ->and($declarations[1]->value)->toBeInstanceOf(ColorNode::class)
                ->and($declarations[1]->value->value)->toBe('#ff0000');
        });

        it('parses number values with units', function () {
            $source = '.numbers { width: 100px; opacity: 0.5; }';

            $ast = $this->parser->parse($source);

            $declarations = $ast->children[0]->children;

            expect($declarations[0]->value)->toBeInstanceOf(NumberNode::class)
                ->and($declarations[0]->value->value)->toBe(100)
                ->and($declarations[0]->value->unit)->toBe('px')
                ->and($declarations[1]->value)->toBeInstanceOf(NumberNode::class)
                ->and($declarations[1]->value->value)->toBe(0.5)
                ->and($declarations[1]->value->unit)->toBeNull();
        });

        it('parses @use directives', function () {
            $source = '@use "functions"; @use "sass:color" as *;';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(UseNode::class)
                ->and($ast->children[0]->path)->toBe('functions')
                ->and($ast->children[0]->namespace)->toBeNull()
                ->and($ast->children[1])->toBeInstanceOf(UseNode::class)
                ->and($ast->children[1]->path)->toBe('sass:color')
                ->and($ast->children[1]->namespace)->toBe('*');
        });

        it('parses @import directives', function () {
            $source = '@import "_imported.scss";';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ImportNode::class)
                ->and($ast->children[0]->imports)->toBe(['"_imported.scss"']);
        });

        it('parses @import directives with multiple files', function () {
            $source = '@import "code", "lists";';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ImportNode::class)
                ->and($ast->children[0]->imports)->toBe(['"code"', '"lists"']);
        });

        it('parses css-like @import directives as raw import entries', function () {
            $source = '@import "theme.css", "http://example.com/a.css", url(theme), "landscape" screen and (orientation: landscape);';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ImportNode::class)
                ->and($ast->children[0]->imports)->toBe([
                    '"theme.css"',
                    '"http://example.com/a.css"',
                    'url(theme)',
                    '"landscape" screen and (orientation: landscape)',
                ]);
        });

        it('keeps bracketed raw @import fragments intact', function () {
            $source = '@import url(theme) [foo, bar];';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ImportNode::class)
                ->and($ast->children[0]->imports)->toBe(['url(theme) [foo, bar]']);
        });

        it('parses @forward directives', function () {
            $source = '@forward "_forwarded.scss";';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ForwardNode::class)
                ->and($ast->children[0]->path)->toBe('_forwarded.scss');
        });

        it('parses @forward directives with as prefix-*', function () {
            $source = '@forward "_forwarded.scss" as list-*;';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ForwardNode::class)
                ->and($ast->children[0]->path)->toBe('_forwarded.scss')
                ->and($ast->children[0]->prefix)->toBe('list-');
        });

        it('parses @forward directives with hide members', function () {
            $source = '@forward "_forwarded.scss" hide $forward-color, forwarded-fn;';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ForwardNode::class)
                ->and($ast->children[0]->path)->toBe('_forwarded.scss')
                ->and($ast->children[0]->visibility)->toBe('hide')
                ->and($ast->children[0]->members)->toBe(['$forward-color', 'forwarded-fn']);
        });

        it('parses @forward directives with show members', function () {
            $source = '@forward "_forwarded.scss" show $forward-color, forwarded-mixin;';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ForwardNode::class)
                ->and($ast->children[0]->path)->toBe('_forwarded.scss')
                ->and($ast->children[0]->visibility)->toBe('show')
                ->and($ast->children[0]->members)->toBe(['$forward-color', 'forwarded-mixin']);
        });

        it('parses @forward directives with visibility and configuration', function () {
            $source = '@forward "_forwarded.scss" show $public with ($primary: blue !default);';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ForwardNode::class)
                ->and($ast->children[0]->visibility)->toBe('show')
                ->and($ast->children[0]->members)->toBe(['$public'])
                ->and($ast->children[0]->configuration)->toHaveKey('primary')
                ->and($ast->children[0]->configuration['primary']['default'])->toBeTrue();
        });

        it('parses @forward directives with configuration and !default', function () {
            $source = '@forward "code" with ($black: #222 !default, $radius: 1rem);';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ForwardNode::class)
                ->and($ast->children[0]->path)->toBe('code')
                ->and($ast->children[0]->configuration)->toHaveKeys(['black', 'radius'])
                ->and($ast->children[0]->configuration['black']['default'])->toBeTrue()
                ->and($ast->children[0]->configuration['radius']['default'])->toBeFalse()
                ->and($ast->children[0]->configuration['black']['value'])->toBeInstanceOf(ColorNode::class)
                ->and($ast->children[0]->configuration['radius']['value'])->toBeInstanceOf(NumberNode::class);
        });

        it('returns empty configuration when @use with is not followed by parentheses', function () {
            $source = '@use "functions" with;';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(UseNode::class)
                ->and($ast->children[0]->path)->toBe('functions')
                ->and($ast->children[0]->configuration)->toBe([]);
        });

        it('parses @include directives', function () {
            $source = '.test { @include mixin-name; @include namespace.mixin(10px, #fff); }';

            $ast = $this->parser->parse($source);

            $includes = [
                $ast->children[0]->children[0],
                $ast->children[0]->children[1],
            ];

            expect($includes[0])->toBeInstanceOf(IncludeNode::class)
                ->and($includes[0]->namespace)->toBeNull()
                ->and($includes[0]->name)->toBe('mixin-name')
                ->and($includes[0]->arguments)->toBeArray()
                ->and(count($includes[0]->arguments))->toBe(0)
                ->and($includes[1])->toBeInstanceOf(IncludeNode::class)
                ->and($includes[1]->namespace)->toBe('namespace')
                ->and($includes[1]->name)->toBe('mixin')
                ->and(count($includes[1]->arguments))->toBe(2)
                ->and($includes[1]->arguments[0])->toBeInstanceOf(NumberNode::class)
                ->and($includes[1]->arguments[0]->value)->toBe(10)
                ->and($includes[1]->arguments[1])->toBeInstanceOf(ColorNode::class)
                ->and($includes[1]->arguments[1]->value)->toBe('#fff');
        });

        it('parses @include using() content arguments', function () {
            $source = <<<'SCSS'
            @include media(screen, print) using ($type) {
              h1 { font-size: 40px; }
            }
            SCSS;

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(IncludeNode::class)
                ->and($ast->children[0]->name)->toBe('media')
                ->and($ast->children[0]->arguments)->toHaveCount(2)
                ->and($ast->children[0]->contentArguments)->toHaveCount(1)
                ->and($ast->children[0]->contentArguments[0])->toBeInstanceOf(ArgumentNode::class)
                ->and($ast->children[0]->contentArguments[0]->name)->toBe('type')
                ->and($ast->children[0]->contentBlock)->toHaveCount(1);
        });

        it('parses bare identifiers in @include using() content arguments', function () {
            $source = <<<'SCSS'
            @include media(screen) using (type) {
              h1 { font-size: 40px; }
            }
            SCSS;

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(IncludeNode::class)
                ->and($ast->children[0]->contentArguments)->toHaveCount(1)
                ->and($ast->children[0]->contentArguments[0])->toBeInstanceOf(ArgumentNode::class)
                ->and($ast->children[0]->contentArguments[0]->name)->toBe('type')
                ->and($ast->children[0]->contentArguments[0]->defaultValue)->toBeNull();
        });

        it('parses @extend directives inside rules', function () {
            $source = '.test { @extend .base; }';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(RuleNode::class)
                ->and($ast->children[0]->children[0])->toBeInstanceOf(ExtendNode::class)
                ->and($ast->children[0]->children[0]->selector)->toBe('.base');
        });

        it('parses @at-root directives', function () {
            $source = '.test { @at-root { .outside { color: red; } } }';

            $ast    = $this->parser->parse($source);
            $atRoot = $ast->children[0]->children[0];

            expect($atRoot)->toBeInstanceOf(AtRootNode::class)
                ->and($atRoot->body)->toHaveCount(1)
                ->and($atRoot->body[0])->toBeInstanceOf(RuleNode::class)
                ->and($atRoot->body[0]->selector)->toBe('.outside');
        });

        it('parses @debug, @warn and @error directives', function () {
            $source = '@debug hello; @warn careful; @error stop;';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(DebugNode::class)
                ->and($ast->children[0]->line)->toBe(1)
                ->and($ast->children[0]->column)->toBe(1)
                ->and($ast->children[1])->toBeInstanceOf(WarnNode::class)
                ->and($ast->children[1]->line)->toBe(1)
                ->and($ast->children[1]->column)->toBe(15)
                ->and($ast->children[2])->toBeInstanceOf(ErrorNode::class)
                ->and($ast->children[2]->line)->toBe(1)
                ->and($ast->children[2]->column)->toBe(30);
        });

        it('parses @use directive with namespace and with configuration', function () {
            $source = '@use "_configurable.scss" as cfg with ($primary: blue, $gap: 12px);';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(UseNode::class)
                ->and($ast->children[0]->path)->toBe('_configurable.scss')
                ->and($ast->children[0]->namespace)->toBe('cfg')
                ->and($ast->children[0]->configuration)->toHaveCount(2)
                ->and($ast->children[0]->configuration)->toHaveKeys(['primary', 'gap']);
        });

        it('parses @mixin directives', function () {
            $source = '@mixin button-style($color) { color: $color; border: 1px solid $color; }';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(MixinNode::class)
                ->and($ast->children[0]->name)->toBe('button-style');
        });

        it('parses @function directives', function () {
            $source = '@function scale($value, $factor: 2) { @return $value * $factor; }';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and($ast->children[0]->name)->toBe('scale')
                ->and($ast->children[0]->arguments)->toHaveCount(2)
                ->and($ast->children[0]->body)->toHaveCount(1)
                ->and($ast->children[0]->body[0])->toBeInstanceOf(ReturnNode::class);

            $returnValue = $ast->children[0]->body[0]->value;
            expect($returnValue)->toBeInstanceOf(ListNode::class)
                ->and($returnValue->separator)->toBe('space')
                ->and($returnValue->items)->toHaveCount(3);
        });

        it('parses rest parameter in @function directives', function () {
            $source = '@function collect($args...) { @return $args; }';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(FunctionDeclarationNode::class)
                ->and($ast->children[0]->arguments)->toHaveCount(1)
                ->and($ast->children[0]->arguments[0])->toBeInstanceOf(ArgumentNode::class)
                ->and($ast->children[0]->arguments[0]->name)->toBe('args')
                ->and($ast->children[0]->arguments[0]->rest)->toBeTrue();
        });

        it('parses @for directives', function () {
            $source = '@for $i from 1 through 3 { .item-$i { width: $i; } }';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(ForNode::class)
                ->and($ast->children[0]->variable)->toBe('i')
                ->and($ast->children[0]->inclusive)->toBeTrue()
                ->and($ast->children[0]->body)->toHaveCount(1);
        });

        it('parses @while directives', function () {
            $source = '$i: 1; @while $i <= 3 { .item { width: $i; } $i: $i + 1; }';

            $ast = $this->parser->parse($source);

            expect($ast->children[1])->toBeInstanceOf(WhileNode::class)
                ->and($ast->children[1]->condition)->toBe('$i <= 3')
                ->and($ast->children[1]->body)->toHaveCount(2);
        });

        it('parses @supports directives', function () {
            $source = '@supports (display: grid) { .item { display: grid; } }';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(SupportsNode::class)
                ->and($ast->children[0]->condition)->toBe('(display: grid)')
                ->and($ast->children[0]->body)->toHaveCount(1)
                ->and($ast->children[0]->body[0])->toBeInstanceOf(RuleNode::class);
        });

        it('parses function calls', function () {
            $source = '.test { background: color.mix(#fff, #000, 20%); }';

            $ast = $this->parser->parse($source);

            $functionCall = $ast->children[0]->children[0]->value;
            expect($functionCall)->toBeInstanceOf(FunctionNode::class)
                ->and($functionCall->name)->toBe('color.mix')
                ->and(count($functionCall->arguments))->toBe(3);
        });

        it('parses spread arguments in function calls', function () {
            $source = '.test { value: list.zip($values...); }';

            $ast = $this->parser->parse($source);

            $functionCall = $ast->children[0]->children[0]->value;
            expect($functionCall)->toBeInstanceOf(FunctionNode::class)
                ->and($functionCall->arguments)->toHaveCount(1)
                ->and($functionCall->arguments[0])->toBeInstanceOf(SpreadArgumentNode::class);
        });

        it('parses identifier followed by parenthesized expression as declaration value, not function call', function () {
            $source = '.slider { transition: left (120px - 10px) * $speed; }';

            $ast = $this->parser->parse($source);

            $value = $ast->children[0]->children[0]->value;
            expect($value)->toBeInstanceOf(ListNode::class)
                ->and($value->separator)->toBe('space')
                ->and($value->items)->toHaveCount(4)
                ->and($value->items[0])->toBeInstanceOf(StringNode::class)
                ->and($value->items[0]->value)->toBe('left')
                ->and($value->items[1])->toBeInstanceOf(ListNode::class)
                ->and($value->items[2])->toBeInstanceOf(StringNode::class)
                ->and($value->items[2]->value)->toBe('*')
                ->and($value->items[3])->toBeInstanceOf(VariableReferenceNode::class)
                ->and($value->items[3]->name)->toBe('speed');
        });

        it('parses bracketed lists', function () {
            $source = '.test { values: [1, 2, 3]; }';

            $ast   = $this->parser->parse($source);
            $value = $ast->children[0]->children[0]->value;

            expect($value)->toBeInstanceOf(ListNode::class)
                ->and($value->separator)->toBe('comma')
                ->and($value->bracketed)->toBeTrue()
                ->and(count($value->items))->toBe(3);
        });
        it('parses map literals in declarations', function () {
            $source = '.test { data: (a: 1, b: 2); }';

            $ast   = $this->parser->parse($source);
            $value = $ast->children[0]->children[0]->value;

            expect($value)->toBeInstanceOf(MapNode::class)
                ->and(count($value->pairs))->toBe(2)
                ->and($value->pairs[0]->key)->toBeInstanceOf(StringNode::class)
                ->and($value->pairs[0]->key->value)->toBe('a');
        });
        it('parses variable declarations', function () {
            $source = '$primary-color: #333; $font-size: 16px;';

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($ast->children[0]->name)->toBe('primary-color')
                ->and($ast->children[0]->value)->toBeInstanceOf(ColorNode::class)
                ->and($ast->children[0]->value->value)->toBe('#333')
                ->and($ast->children[1])->toBeInstanceOf(VariableDeclarationNode::class)
                ->and($ast->children[1]->name)->toBe('font-size')
                ->and($ast->children[1]->value)->toBeInstanceOf(NumberNode::class)
                ->and($ast->children[1]->value->value)->toBe(16)
                ->and($ast->children[1]->value->unit)->toBe('px');
        });

        it('parses pseudo-selectors and combinators', function () {
            $source = '.item:hover { color: blue; } #id > .child + .sibling { margin: 5px; }';

            $ast = $this->parser->parse($source);

            expect($ast->children[0]->selector)->toBe('.item:hover')
                ->and($ast->children[1]->selector)->toBe('#id > .child + .sibling');
        });

        it('parses complex selector combinations', function () {
            $source = '#lp_blocks .item &:hover { box-shadow: 0 2px 5px rgba(0, 0, 0, .3); }';

            $ast = $this->parser->parse($source);

            $rule = $ast->children[0];
            expect($rule->selector)->toBe('#lp_blocks .item &:hover');

            $declaration = $rule->children[0];
            expect($declaration->property)->toBe('box-shadow');
        });

        it('handles comments and whitespace', function () {
            $source = "/* comment */\n.test {\n  // another comment\n  color: red;\n}";

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(CommentNode::class)
                ->and($ast->children[0]->value)->toBe('comment')
                ->and($ast->children[0]->isPreserved)->toBeFalse()
                ->and($ast->children[1]->selector)->toBe('.test')
                ->and($ast->children[1]->children[0])->toBeInstanceOf(DeclarationNode::class)
                ->and($ast->children[1]->children[0]->property)->toBe('color');
        });

        it('ignores silent comments', function () {
            $source = <<<SCSS
            // comment
            .item {
                color: red;
            }
            SCSS;

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(RuleNode::class)
                ->and($ast->children[0]->selector)->toBe('.item');
        });

        it('parses loud comments', function () {
            $source = <<<SCSS
            /* comment */
            .item {
                color: red;
            }
            SCSS;

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(CommentNode::class)
                ->and($ast->children[0]->value)->toBe('comment')
                ->and($ast->children[0]->isPreserved)->toBeFalse();
        });

        it('parses preserved (strong) comments', function () {
            $source = <<<SCSS
            /*! comment */
            .item {
                color: red;
            }
            SCSS;

            $ast = $this->parser->parse($source);

            expect($ast->children[0])->toBeInstanceOf(CommentNode::class)
                ->and($ast->children[0]->value)->toBe('comment')
                ->and($ast->children[0]->isPreserved)->toBeTrue();
        });

        it('handles complex input', function () {
            $source = <<<'SCSS'
            @use "functions";
            @use "sass:color";

            @mixin test-mixin($color) {
                color: $color;
                border: 1px solid $color;
                @if $color == #333 {
                    outline: 1px solid #333;
                } @else {
                    outline: 1px solid $color;
                }
            }

            .color_tests {
                hex-3: #f00;
                hex-4: #f008;
                hex-6: #ff0000;
                hex-8: #ff000080;

                color-hsl: hsl(120, 100%, 50%);
                color-mixed: color.mix(#fff, #000, 20%);
                color-mixed-named: color.mix($color1: #fff, $color2: #000, $weight: 20%);
            }

            #lp_blocks {
                .item {
                    @include functions.pointer;
                    height: 96%;
                    transition: all .2s;
                    text-align: center;
                    overflow: hidden;

                    &:hover {
                        box-shadow: 0 2px 5px rgba(0, 0, 0, .3);
                    }

                    div {
                        font-size: 16px;
                        margin: 10px;
                    }

                    p {
                        text-align: left;
                        font-size: 14px;
                    }
                }

                margin-bottom: 1em;
            }

            .preview_frame {
                margin: 10px;
                padding: 10px;
                border: thick double #32a1ce;
                @include functions.bRadius;
                overflow: auto;
                @include test-mixin(#333);
            }
            SCSS;

            $expected = new RootNode([
                new UseNode('functions'),
                new UseNode('sass:color'),
                new MixinNode('test-mixin', [new ArgumentNode('color')], [
                    new DeclarationNode('color', new VariableReferenceNode('color'), 5, 5),
                    new DeclarationNode('border', new ListNode([
                        new NumberNode(1, 'px'),
                        new StringNode('solid', line: 6, column: 17),
                        new VariableReferenceNode('color'),
                    ]), 6, 5),
                    new IfNode('$color == #333', [
                        new DeclarationNode('outline', new ListNode([
                            new NumberNode(1, 'px'),
                            new StringNode('solid', line: 8, column: 22),
                            new ColorNode('#333'),
                        ]), 8, 9),
                    ], [], [
                        new DeclarationNode('outline', new ListNode([
                            new NumberNode(1, 'px'),
                            new StringNode('solid', line: 10, column: 22),
                            new VariableReferenceNode('color'),
                        ]), 10, 9),
                    ]),
                ], 4),
                new RuleNode('.color_tests', [
                    new DeclarationNode('hex-3', new ColorNode('#f00'), 15, 5),
                    new DeclarationNode('hex-4', new ColorNode('#f008'), 16, 5),
                    new DeclarationNode('hex-6', new ColorNode('#ff0000'), 17, 5),
                    new DeclarationNode('hex-8', new ColorNode('#ff000080'), 18, 5),
                    new DeclarationNode('color-hsl', new FunctionNode('hsl', [
                        new NumberNode(120),
                        new NumberNode(100, '%'),
                        new NumberNode(50, '%'),
                    ], 20), 20, 5),
                    new DeclarationNode('color-mixed', new FunctionNode('color.mix', [
                        new ColorNode('#fff'),
                        new ColorNode('#000'),
                        new NumberNode(20, '%'),
                    ], 21), 21, 5),
                    new DeclarationNode('color-mixed-named', new FunctionNode('color.mix', [
                        new NamedArgumentNode('color1', new ColorNode('#fff')),
                        new NamedArgumentNode('color2', new ColorNode('#000')),
                        new NamedArgumentNode('weight', new NumberNode(20, '%')),
                    ], 22), 22, 5),
                ], 14, 1),
                new RuleNode('#lp_blocks', [
                    new RuleNode('.item', [
                        new IncludeNode('functions', 'pointer'),
                        new DeclarationNode('height', new NumberNode(96, '%'), 28, 9),
                        new DeclarationNode('transition', new ListNode([
                            new StringNode('all', line: 29, column: 21),
                            new NumberNode(0.2, 's'),
                        ]), 29, 9),
                        new DeclarationNode('text-align', new StringNode('center', line: 30, column: 21), 30, 9),
                        new DeclarationNode('overflow', new StringNode('hidden', line: 31, column: 19), 31, 9),
                        new RuleNode('&:hover', [
                            new DeclarationNode('box-shadow', new ListNode([
                                new NumberNode(0),
                                new NumberNode(2, 'px'),
                                new NumberNode(5, 'px'),
                                new FunctionNode('rgba', [
                                    new NumberNode(0),
                                    new NumberNode(0),
                                    new NumberNode(0),
                                    new NumberNode(0.3),
                                ], 34),
                            ]), 34, 13),
                        ], 33, 9),
                        new RuleNode('div', [
                            new DeclarationNode('font-size', new NumberNode(16, 'px'), 38, 13),
                            new DeclarationNode('margin', new NumberNode(10, 'px'), 39, 13),
                        ], 37, 9),
                        new RuleNode('p', [
                            new DeclarationNode('text-align', new StringNode('left', line: 43, column: 25), 43, 13),
                            new DeclarationNode('font-size', new NumberNode(14, 'px'), 44, 13),
                        ], 42, 9),
                    ], 26, 5),
                    new DeclarationNode('margin-bottom', new NumberNode(1, 'em'), 48, 5),
                ], 25, 1),
                new RuleNode('.preview_frame', [
                    new DeclarationNode('margin', new NumberNode(10, 'px'), 52, 5),
                    new DeclarationNode('padding', new NumberNode(10, 'px'), 53, 5),
                    new DeclarationNode('border', new ListNode([
                        new StringNode('thick', line: 54, column: 13),
                        new StringNode('double', line: 54, column: 19),
                        new ColorNode('#32a1ce'),
                    ]), 54, 5),
                    new IncludeNode('functions', 'bRadius'),
                    new DeclarationNode('overflow', new StringNode('auto', line: 56, column: 15), 56, 5),
                    new IncludeNode(null, 'test-mixin', [
                        new ColorNode('#333'),
                    ]),
                ], 51, 1),
            ]);

            $ast = $this->parser->parse($source);

            expect($ast)->toEqual($expected);
        });

        it('parses vendor prefixes correctly', function () {
            $source = '.test-class { display: -webkit-box; display: -moz-box; display: -ms-flexbox; display: flex; }';

            $ast = $this->parser->parse($source);

            $declarations = $ast->children[0]->children;

            expect($declarations[0]->property)->toBe('display')
                ->and($declarations[0]->value)->toBeInstanceOf(StringNode::class)
                ->and($declarations[0]->value->value)->toBe('-webkit-box')
                ->and($declarations[1]->property)->toBe('display')
                ->and($declarations[1]->value)->toBeInstanceOf(StringNode::class)
                ->and($declarations[1]->value->value)->toBe('-moz-box')
                ->and($declarations[2]->property)->toBe('display')
                ->and($declarations[2]->value)->toBeInstanceOf(StringNode::class)
                ->and($declarations[2]->value->value)->toBe('-ms-flexbox')
                ->and($declarations[3]->property)->toBe('display')
                ->and($declarations[3]->value)->toBeInstanceOf(StringNode::class)
                ->and($declarations[3]->value->value)->toBe('flex');

        });

        it('parses comma-separated lists with space-separated values', function () {
            $source = '.test { box-shadow: 0 1px 3px 0 rgba(0, 0, 0, .1), 0 1px 2px 0 rgba(0, 0, 0, .06); }';

            $ast = $this->parser->parse($source);

            $declaration = $ast->children[0]->children[0];
            expect($declaration->property)->toBe('box-shadow')
                ->and($declaration->value)->toBeInstanceOf(ListNode::class)
                ->and($declaration->value->separator)->toBe('comma')
                ->and(count($declaration->value->items))->toBe(2);

            // First group: 0 1px 3px 0 rgba(0, 0, 0, .1)
            $firstGroup = $declaration->value->items[0];
            expect($firstGroup)->toBeInstanceOf(ListNode::class)
                ->and($firstGroup->separator)->toBe('space')
                ->and(count($firstGroup->items))->toBe(5)
                ->and($firstGroup->items[0])->toBeInstanceOf(NumberNode::class)
                ->and($firstGroup->items[0]->value)->toBe(0)
                ->and($firstGroup->items[1])->toBeInstanceOf(NumberNode::class)
                ->and($firstGroup->items[1]->value)->toBe(1)
                ->and($firstGroup->items[1]->unit)->toBe('px')
                ->and($firstGroup->items[2])->toBeInstanceOf(NumberNode::class)
                ->and($firstGroup->items[2]->value)->toBe(3)
                ->and($firstGroup->items[2]->unit)->toBe('px')
                ->and($firstGroup->items[3])->toBeInstanceOf(NumberNode::class)
                ->and($firstGroup->items[3]->value)->toBe(0)
                ->and($firstGroup->items[4])->toBeInstanceOf(FunctionNode::class)
                ->and($firstGroup->items[4]->name)->toBe('rgba')
                ->and(count($firstGroup->items[4]->arguments))->toBe(4);

            // Second group: 0 1px 2px 0 rgba(0, 0, 0, .06)
            $secondGroup = $declaration->value->items[1];
            expect($secondGroup)->toBeInstanceOf(ListNode::class)
                ->and($secondGroup->separator)->toBe('space')
                ->and(count($secondGroup->items))->toBe(5);
        });

        it('parses function arguments with space-separated values', function () {
            $source = '.test { background-image: linear-gradient(to bottom, transparent, #111827); }';

            $ast = $this->parser->parse($source);

            $functionCall = $ast->children[0]->children[0]->value;
            expect($functionCall)->toBeInstanceOf(FunctionNode::class)
                ->and($functionCall->name)->toBe('linear-gradient')
                ->and(count($functionCall->arguments))->toBe(3);

            // First argument should be "to bottom" as a space-separated list
            $firstArg = $functionCall->arguments[0];
            expect($firstArg)->toBeInstanceOf(ListNode::class)
                ->and($firstArg->separator)->toBe('space')
                ->and(count($firstArg->items))->toBe(2)
                ->and($firstArg->items[0]->value)->toBe('to')
                ->and($firstArg->items[1]->value)->toBe('bottom');

            // Second argument should be "transparent"
            $secondArg = $functionCall->arguments[1];
            expect($secondArg)->toBeInstanceOf(StringNode::class)
                ->and($secondArg->value)->toBe('transparent');

            // Third argument should be color
            $thirdArg = $functionCall->arguments[2];
            expect($thirdArg)->toBeInstanceOf(ColorNode::class)
                ->and($thirdArg->value)->toBe('#111827');
        });
    });

    it('handles empty input gracefully', function () {
        $ast = $this->parser->parse('');
        expect($ast)->toBeInstanceOf(RootNode::class)
            ->and(count($ast->children))->toBe(0);
    });

    it('stops parsing a block when only whitespace remains before eof', function () {
        $ast = $this->parser->parse('@mixin sample {   ');

        expect($ast)->toBeInstanceOf(RootNode::class)
            ->and($ast->children)->toHaveCount(1)
            ->and($ast->children[0])->toBeInstanceOf(MixinNode::class)
            ->and($ast->children[0]->body)->toBe([]);
    });

    it('returns an empty string node for blank inline expressions through public api', function () {
        $value = $this->parser->parseInlineValue('   ');

        expect($value)->toBeInstanceOf(StringNode::class)
            ->and($value->value)->toBe('');
    });

    it('returns the raw inline expression when inline parsing produces a non-declaration child', function () {
        $value = $this->parser->parseInlineValue('@if true {a:b}');

        expect($value)->toBeInstanceOf(StringNode::class)
            ->and($value->value)->toBe('@if true {a:b}');
    });

    it('returns the raw inline expression when it cannot extract a declaration value', function () {
        $value = $this->parser->parseInlineValue('broken');

        expect($value)->toBeInstanceOf(StringNode::class)
            ->and($value->value)->toBe('broken');
    });

    it('stops parsing a directive block when the next statement cannot be parsed', function () {
        $ast = $this->parser->parse('@mixin sample { x }');

        expect($ast)->toBeInstanceOf(RootNode::class)
            ->and($ast->children)->not->toBe([])
            ->and($ast->children[0])->toBeInstanceOf(MixinNode::class)
            ->and($ast->children[0]->body)->toHaveCount(0);
    });

    it('breaks out of directive block parsing after the loop guard threshold', function () {
        $comments = [];

        for ($i = 0; $i < 1001; $i++) {
            $comments[] = '/*! kept */';
        }

        $ast = $this->parser->parse('@mixin sample { ' . implode(' ', $comments) . ' }');

        expect($ast)->toBeInstanceOf(RootNode::class)
            ->and($ast->children)->toHaveCount(2)
            ->and($ast->children[0])->toBeInstanceOf(MixinNode::class)
            ->and($ast->children[0]->body)->toHaveCount(1000)
            ->and($ast->children[0]->body[0])->toBeInstanceOf(CommentNode::class);
    });

    it('parses legacy = operator in function arguments for IE compatibility', function () {
        $source = '.test { filter: chroma(color=#0000ff); }';

        $ast = $this->parser->parse($source);

        $rule = $ast->children[0];
        expect($rule)->toBeInstanceOf(RuleNode::class)
            ->and($rule->selector)->toBe('.test');

        $declaration = $rule->children[0];
        expect($declaration)->toBeInstanceOf(DeclarationNode::class)
            ->and($declaration->property)->toBe('filter');

        $functionCall = $declaration->value;
        expect($functionCall)->toBeInstanceOf(FunctionNode::class)
            ->and($functionCall->name)->toBe('chroma')
            ->and(count($functionCall->arguments))->toBe(1);

        $argument = $functionCall->arguments[0];
        expect($argument)->toBeInstanceOf(StringNode::class)
            ->and($argument->value)->toBe('color=#0000ff')
            ->and($argument->quoted)->toBeFalse();
    });
});
