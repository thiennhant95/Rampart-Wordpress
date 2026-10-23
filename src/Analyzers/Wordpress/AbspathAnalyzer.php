<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WP_ABSPATH — plugin entry files without the ABSPATH guard.
 *
 * A top-level PHP file carrying a `Plugin Name:` header is a plugin entry
 * point: without `defined('ABSPATH') || exit` (or equivalent) it executes on
 * direct web access. Deterministic check (Warning/High): header present via a
 * case-insensitive text search (headers live in comments, which the AST does
 * not model), guard present via any `defined('ABSPATH')` call in the file.
 */
final class AbspathAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WP_ABSPATH';

    /**
     * @param list<string> $files
     * @return list<Issue>
     */
    public function analyze(array $files): array
    {
        $issues = [];
        foreach ($files as $file) {
            if (!$this->supports($file)) {
                continue;
            }
            $code = $this->sharedSource($file);
            if ($code === '' || stripos($code, 'Plugin Name:') === false) {
                continue;
            }
            $ast = $this->sharedAst($file);
            if ($ast === null) {
                continue;
            }
            $guarded = $this->finder()->find($ast, static function (Node $node): bool {
                if (!$node instanceof Node\Expr\FuncCall || !$node->name instanceof Node\Name) {
                    return false;
                }
                if (strtolower($node->name->toString()) !== 'defined') {
                    return false;
                }
                $arg = $node->getArgs()[0] ?? null;

                return $arg instanceof Node\Arg
                    && $arg->value instanceof Node\Scalar\String_
                    && strtoupper($arg->value->value) === 'ABSPATH';
            });
            if ($guarded !== []) {
                continue;
            }
            $issues[] = $this->makeIssue(
                self::RULE,
                "Plugin entry file has no ABSPATH guard — add defined('ABSPATH') || exit; at the top.",
                $file,
                1,
                Severity::Warning,
                [],
                Confidence::High
            );
        }

        return $issues;
    }
}
