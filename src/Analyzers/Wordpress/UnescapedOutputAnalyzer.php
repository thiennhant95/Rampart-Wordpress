<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WP_UNESCAPED_OUTPUT — echo/print of unescaped dynamic content.
 *
 * WordPress rule (mirrors WPCS `WordPress.Security.EscapeOutput`): everything
 * echoed must pass an escaping function. Clean: string/number literals,
 * `true`/`false`/`null`, casts, and calls to the escaping family —
 * `esc_html*()`, `esc_attr*()`, `esc_url*()`, `esc_textarea()`, `esc_js()`,
 * `wp_kses*()`. Everything else echoed (variables, interpolations, unknown
 * calls) flags at Warning/Medium.
 *
 * Deliberately NOT whitelisted: `date_i18n()` (tainted format is suspicious),
 * `__()`/`_e()` (translation, not escaping — `esc_html__()` is the safe one).
 */
final class UnescapedOutputAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WP_UNESCAPED_OUTPUT';

    /** @var list<string> */
    private const ESCAPERS = [
        'esc_html', 'esc_html__', 'esc_html_e',
        'esc_attr', 'esc_attr__', 'esc_attr_e',
        'esc_url', 'esc_url_raw',
        'esc_textarea',
        'esc_js',
        'wp_kses', 'wp_kses_post', 'wp_kses_data',
    ];

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
            $outputs = $this->finder()->find($ast, static function (Node $node): bool {
                return $node instanceof Node\Stmt\Echo_
                    || $node instanceof Node\Expr\Print_
                    || $node instanceof Node\Stmt\InlineHTML;
            });
            $origins = $this->variableOrigins($ast);
            foreach ($outputs as $output) {
                // Static HTML outside PHP tags carries nothing to escape.
                if ($output instanceof Node\Stmt\Echo_) {
                    $exprs = $output->exprs;
                } elseif ($output instanceof Node\Expr\Print_) {
                    $exprs = [$output->expr];
                } else {
                    continue;
                }
                foreach ($exprs as $expr) {
                    if (!$this->isEscaped($expr, $origins, [])) {
                        $issues[] = $this->makeIssue(
                            self::RULE,
                            'Unescaped output — wrap dynamic content in esc_html()/esc_attr()/wp_kses().',
                            $file,
                            $output->getStartLine(),
                            Severity::Warning,
                            [],
                            Confidence::Medium
                        );
                        break;
                    }
                }
            }
        }

        return $issues;
    }

    /**
     * @param array<string, list<Node\Expr>> $origins
     * @param array<string, true> $seen recursion guard ($x = $x . 'a')
     */
    private function isEscaped(Node\Expr $expr, array $origins = [], array $seen = []): bool
    {
        if (
            $expr instanceof Node\Scalar\String_
            || $expr instanceof Node\Scalar\LNumber
            || $expr instanceof Node\Scalar\DNumber
        ) {
            return true;
        }
        if ($expr instanceof Node\Expr\ConstFetch) {
            return true;
        }
        if ($expr instanceof Node\Expr\Cast) {
            return true;
        }
        if (
            $expr instanceof Node\Expr\FuncCall
            && $expr->name instanceof Node\Name
            && in_array(strtolower($expr->name->toString()), self::ESCAPERS, true)
        ) {
            return true;
        }
        if ($expr instanceof Node\Expr\Variable && is_string($expr->name)) {
            // Literal temps ($x = 'a'; echo $x) are clean; parameters with no
            // same-file origin stay flagged (conservative).
            if (isset($seen[$expr->name]) || !isset($origins[$expr->name])) {
                return isset($seen[$expr->name]);
            }
            $seen[$expr->name] = true;
            foreach ($origins[$expr->name] as $origin) {
                if (!$this->isEscaped($origin, $origins, $seen)) {
                    return false;
                }
            }

            return true;
        }
        if ($expr instanceof Node\Expr\BinaryOp\Concat) {
            return $this->isEscaped($expr->left, $origins, $seen) && $this->isEscaped($expr->right, $origins, $seen);
        }
        if ($expr instanceof Node\Expr\BinaryOp\Coalesce) {
            return $this->isEscaped($expr->left, $origins, $seen) && $this->isEscaped($expr->right, $origins, $seen);
        }
        if ($expr instanceof Node\Expr\Ternary) {
            // Only output branches matter: the condition itself is never
            // printed. Shorthand ?: reuses the condition as a branch.
            if ($expr->if === null) {
                return $this->isEscaped($expr->cond, $origins, $seen) && $this->isEscaped($expr->else, $origins, $seen);
            }

            return $this->isEscaped($expr->if, $origins, $seen) && $this->isEscaped($expr->else, $origins, $seen);
        }
        if ($expr instanceof Node\Scalar\InterpolatedString) {
            foreach ($expr->parts as $part) {
                if ($part instanceof Node\Expr && !$this->isEscaped($part, $origins, $seen)) {
                    return false;
                }
            }

            return true;
        }

        return false;
    }
}
