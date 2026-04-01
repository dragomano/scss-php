<?php

declare(strict_types=1);

namespace Bugo\SCSS\Values;

use Bugo\SCSS\Nodes\ArgumentListNode;
use Bugo\SCSS\Nodes\AstNode;
use Bugo\SCSS\Nodes\BooleanNode;
use Bugo\SCSS\Nodes\ColorNode;
use Bugo\SCSS\Nodes\FunctionNode;
use Bugo\SCSS\Nodes\ListNode;
use Bugo\SCSS\Nodes\MapNode;
use Bugo\SCSS\Nodes\MixinRefNode;
use Bugo\SCSS\Nodes\NullNode;
use Bugo\SCSS\Nodes\NumberNode;

enum AstValueType: string
{
    case ArgList     = 'arglist';
    case Bool        = 'bool';
    case Calculation = 'calculation';
    case Color       = 'color';
    case Function    = 'function';
    case List        = 'list';
    case Map         = 'map';
    case Mixin       = 'mixin';
    case Null        = 'null';
    case Number      = 'number';
    case String      = 'string';

    public static function fromNode(AstNode $node): self
    {
        if ($node instanceof NumberNode) {
            return self::Number;
        }

        if ($node instanceof ColorNode) {
            return self::Color;
        }

        if ($node instanceof ArgumentListNode) {
            return self::ArgList;
        }

        if ($node instanceof ListNode) {
            return self::List;
        }

        if ($node instanceof MapNode) {
            return self::Map;
        }

        if ($node instanceof FunctionNode) {
            if (SassCalculation::isCalculationFunctionName($node->name)) {
                return self::Calculation;
            }

            return self::Function;
        }

        if ($node instanceof MixinRefNode) {
            return self::Mixin;
        }

        if ($node instanceof BooleanNode) {
            return self::Bool;
        }

        if ($node instanceof NullNode) {
            return self::Null;
        }

        return self::String;
    }
}
