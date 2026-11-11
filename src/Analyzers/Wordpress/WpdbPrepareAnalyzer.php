<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WPDB_PREPARE — unprepared database queries.
 *
 * Sinks: `$wpdb->query/get_results/get_var/get_row/get_col($tainted)` where
 * the argument is not a `$wpdb->prepare(...)` result and not a plain literal.
 * `$wpdb->insert/update/delete/replace` bind by API and never flag.
 * The receiver must be `$wpdb` (case-insensitive); other query builders are
 * out of scope (documented, silent).
 *
 * Tainted (conservative, documented): superglobals, variables assigned from
 * them in the same file, interpolated strings containing an expression, and
 * unknown function/method results. Plain literals and prepare() results are
 * clean. `$wpdb->prepare()` with no placeholders is still clean here (it is
 * at worst useless, not injectable) — a separate convention rule may flag it.
 * Function parameters with no same-file tainted origin are treated as clean
 * (may miss; cross-function taint arrives with a future taint engine).
 */
final class WpdbPrepareAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WPDB_PREPARE';

    /** @var list<string> */
    private const SINKS = ['query', 'get_results', 'get_var', 'get_row', 'get_col'];

    // NOTE: $wpdb->insert/update/delete/replace bind by API and never flag —
    // enforced by SINKS membership above, not by a separate list.

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
            $ast = $this->sharedAst($file);
            if ($ast === null) {
                continue;
            }
            $origins = $this->variableOrigins($ast);
            $calls = $this->finder()->find($ast, static function (Node $node): bool {
                return $node instanceof Node\Expr\MethodCall
                    && $node->name instanceof Node\Identifier;
            });
            foreach ($calls as $call) {
                if (!$call instanceof Node\Expr\MethodCall || !$call->name instanceof Node\Identifier) {
                    continue;
                }
                $method = strtolower($call->name->toString());
                if (!in_array($method, self::SINKS, true)) {
                    continue;
                }
                if (!$call->var instanceof Node\Expr\Variable || !is_string($call->var->name) || strtolower($call->var->name) !== 'wpdb') {
                    continue;
                }
                $arg = $call->getArgs()[0] ?? null;
                if (!$arg instanceof Node\Arg) {
                    continue;
                }
                if ($this->isPrepareCall($arg->value)) {
                    continue;
                }
                if (!$this->isWpTainted($arg->value, $origins)) {
                    continue;
                }
                if ($this->hasWpcsReview($file, $call->getStartLine())) {
                    continue;
                }
                $issues[] = $this->makeIssue(
                    self::RULE,
                    sprintf('Unprepared $wpdb->%s() with dynamic SQL — use $wpdb->prepare() with placeholders.', $call->name->toString()),
                    $file,
                    $call->getStartLine(),
                    Severity::Error,
                    ['method' => $call->name->toString()],
                    Confidence::Medium
                );
            }
        }

        return $issues;
    }

    /**
     * A `phpcs:ignore`/`phpcs:disable WordPress.DB.*` comment on or above the
     * call line is explicit human review (Yoast marks prepared queries this
     * way) — the same deference the tool gives its own inline-suppress
     * comments.
     */
    private function hasWpcsReview(string $file, int $line): bool
    {
        $code = $this->sharedSource($file);
        if ($code === '') {
            return false;
        }
        $lines = explode("\n", $code);
        for ($i = max(0, $line - 3); $i < $line && $i < count($lines); $i++) {
            $trimmed = $lines[$i] ?? '';
            if ((str_contains($trimmed, 'phpcs:ignore') || str_contains($trimmed, 'phpcs:disable')) && str_contains($trimmed, 'WordPress.DB')) {
                return true;
            }
        }

        return false;
    }

    private function isPrepareCall(Node\Expr $expr): bool
    {
        return $expr instanceof Node\Expr\MethodCall
            && $expr->name instanceof Node\Identifier
            && strtolower($expr->name->toString()) === 'prepare';
    }

    /**
     * @param array<string, list<Node\Expr>> $origins
     */
    private function isWpTainted(Node\Expr $expr, array $origins): bool
    {
        if ($expr instanceof Node\Scalar\String_ || $expr instanceof Node\Scalar\LNumber || $expr instanceof Node\Scalar\DNumber) {
            return false;
        }
        if ($expr instanceof Node\Expr\ConstFetch) {
            return false;
        }
        if ($expr instanceof Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\Expr && $this->isWpTainted($part, $origins)) {
                    return true;
                }
            }

            return false;
        }
        // Table-name properties ($wpdb->comments, $wpdb->prefix, …) are
        // WordPress internals, never user input — dogfood FP on Akismet.
        if ($expr instanceof Node\Expr\PropertyFetch || $expr instanceof Node\Expr\NullsafePropertyFetch) {
            $root = $expr->var;
            while ($root instanceof Node\Expr\PropertyFetch || $root instanceof Node\Expr\NullsafePropertyFetch || $root instanceof Node\Expr\ArrayDimFetch) {
                $root = $root->var;
            }
            if ($root instanceof Node\Expr\Variable && is_string($root->name) && strtolower($root->name) === 'wpdb') {
                return false;
            }
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            $lower = strtolower($expr->name);
            if (in_array($lower, ['_get', '_post', '_request', '_cookie', '_files'], true)) {
                return true;
            }
            if (!isset($origins[$expr->name])) {
                return false;
            }
            foreach ($origins[$expr->name] as $origin) {
                if ($this->isWpTainted($origin, $origins)) {
                    return true;
                }
            }

            return false;
        }
        if ($expr instanceof Node\Expr\ArrayDimFetch) {
            return $this->isWpTainted($expr->var, $origins);
        }
        // A prepare() result is clean even through a variable ($sql =
        // $wpdb->prepare(...); $wpdb->query($sql)) — dogfood FP on Yoast.
        if ($expr instanceof Node\Expr\MethodCall && $expr->name instanceof Node\Identifier) {
            if (strtolower($expr->name->toString()) === 'prepare') {
                return false;
            }
        }

        return $this->isTaintedExpr($expr);
    }
}
