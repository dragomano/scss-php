<?php

declare(strict_types=1);

namespace Bugo\SCSS\Nodes;

/**
 * @phpstan-type NodeList array<int, AstNode>
 * @phpstan-type NodeMap  array<string, AstNode>
 *
 * @psalm-type  NodeList = array<int, AstNode>
 * @psalm-type  NodeMap  = array<string, AstNode>
 */
abstract class AstNode {}
