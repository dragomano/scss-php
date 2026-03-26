<?php

declare(strict_types=1);

namespace Bugo\SCSS\Lexer;

enum TokenType: string
{
    case AT                  = 'AT';
    case DOLLAR              = 'DOLLAR';
    case COLON               = 'COLON';
    case DOUBLE_COLON        = 'DOUBLE_COLON';
    case SEMICOLON           = 'SEMICOLON';
    case COMMA               = 'COMMA';
    case LBRACE              = 'LBRACE';
    case RBRACE              = 'RBRACE';
    case LPAREN              = 'LPAREN';
    case RPAREN              = 'RPAREN';
    case LBRACKET            = 'LBRACKET';
    case RBRACKET            = 'RBRACKET';
    case AMPERSAND           = 'AMPERSAND';
    case DOT                 = 'DOT';
    case HASH                = 'HASH';
    case EQUALS              = 'EQUALS';
    case ASSIGN              = 'ASSIGN';
    case NOT_EQUALS          = 'NOT_EQUALS';
    case EXCLAMATION         = 'EXCLAMATION';
    case LESS_THAN           = 'LESS_THAN';
    case GREATER_THAN        = 'GREATER_THAN';
    case LESS_THAN_EQUALS    = 'LESS_THAN_EQUALS';
    case GREATER_THAN_EQUALS = 'GREATER_THAN_EQUALS';
    case PLUS                = 'PLUS';
    case MINUS               = 'MINUS';
    case STAR                = 'STAR';
    case SLASH               = 'SLASH';
    case PERCENT             = 'PERCENT';
    case TILDE               = 'TILDE';
    case STRING              = 'STRING';
    case NUMBER              = 'NUMBER';
    case IDENTIFIER          = 'IDENTIFIER';
    case CSS_VARIABLE        = 'CSS_VARIABLE';
    case WHITESPACE          = 'WHITESPACE';
    case COMMENT_SILENT      = 'COMMENT_SILENT';
    case COMMENT_LOUD        = 'COMMENT_LOUD';
    case COMMENT_PRESERVED   = 'COMMENT_PRESERVED';
    case EOF                 = 'EOF';
}
