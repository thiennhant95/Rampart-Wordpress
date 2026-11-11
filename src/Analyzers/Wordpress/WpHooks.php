<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;

/**
 * Shared WordPress hook/callable resolution.
 *
 * WordPress wires behavior with string callbacks — add_action('hook', 'func'),
 * add_menu_page(..., 'callback') — so analyzers must map hook registrations to
 * the function bodies that run. Supported callback shapes:
 *   - 'function_name' (plain function)
 *   - 'Class::method' (static)
 * Closures and [$obj, 'method'] arrays are not resolved (documented boundary:
 * silently skipped, never flagged on a guess).
 */
final class WpHooks
{
    /**
     * All plain function calls to one of $names in the tree.
     *
     * @param list<Node> $nodes
     * @param list<string> $names lowercase-compared
     * @return list<Node\Expr\FuncCall>
     */
    public static function findCalls(array $nodes, array $names, \PhpParser\NodeFinder $finder): array
    {
        $lower = array_map('strtolower', $names);
        $found = $finder->find($nodes, static function (Node $node) use ($lower): bool {
            return $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->toString()), $lower, true);
        });

        return array_values(array_filter($found, static fn ($n): bool => $n instanceof Node\Expr\FuncCall));
    }

    /**
     * First argument as a plain string, or null (variables/concats unresolved).
     */
    public static function firstStringArg(Node\Expr\FuncCall $call): ?string
    {
        $arg = $call->getArgs()[0] ?? null;
        if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_) {
            return $arg->value->value;
        }

        return null;
    }

    /**
     * Resolve the callback argument (default index 1) to [kind, name].
     *
     * @return array{string, string}|null ['function', name] or ['static', 'Class::method']
     */
    public static function resolveCallback(Node\Expr\FuncCall $call, int $index = 1): ?array
    {
        $arg = $call->getArgs()[$index] ?? null;
        if (!$arg instanceof Node\Arg || !$arg->value instanceof Node\Scalar\String_) {
            return null;
        }
        $name = $arg->value->value;
        if (str_contains($name, '::')) {
            return ['static', $name];
        }
        if (preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $name) === 1) {
            return ['function', $name];
        }

        return null;
    }

    /**
     * Find a function or static method body by name.
     *
     * @param list<Node> $nodes
     */
    public static function findCallable(array $nodes, string $kind, string $name, \PhpParser\NodeFinder $finder): Node\FunctionLike|null
    {
        if ($kind === 'function') {
            $found = $finder->find($nodes, static function (Node $node) use ($name): bool {
                // Function_ always carries a name (closures are Closure nodes).
                return $node instanceof Node\Stmt\Function_
                    && $node->name->toString() === $name;
            });
            $first = $found[0] ?? null;

            return $first instanceof Node\Stmt\Function_ ? $first : null;
        }
        [$class, $method] = explode('::', $name, 2) + [null, null];
        if (!is_string($class) || !is_string($method)) {
            return null;
        }
        $found = $finder->find($nodes, static function (Node $node) use ($class, $method): bool {
            if (!$node instanceof Node\Stmt\ClassMethod) {
                return false;
            }
            if ($node->name->toString() !== $method) {
                return false;
            }
            $parent = $node->getAttribute('parent');
            return $parent instanceof Node\Stmt\Class_
                && $parent->name instanceof Node\Identifier
                && $parent->name->toString() === $class;
        });
        $first = $found[0] ?? null;

        return $first instanceof Node\Stmt\ClassMethod ? $first : null;
    }

    /**
     * Whether the callable body calls any of the given plain functions.
     *
     * @param list<string> $names
     */
    public static function bodyCalls(Node\FunctionLike $func, array $names, \PhpParser\NodeFinder $finder): bool
    {
        $stmts = $func->getStmts();
        if ($stmts === null) {
            return false;
        }
        $lower = array_map('strtolower', $names);
        $found = $finder->find($stmts, static function (Node $node) use ($lower): bool {
            return $node instanceof Node\Expr\FuncCall
                && $node->name instanceof Node\Name
                && in_array(strtolower($node->name->toString()), $lower, true);
        });

        return $found !== [];
    }
}
