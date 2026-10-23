<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WP_TABLE_PREFIX — hardcoded `wp_` table names in SQL strings (Info/Low,
 * opt-in). Sites that rename the table prefix break on literal `wp_`
 * references; `$wpdb->prefix` (or `$wpdb->tables`) must be used instead.
 * Only string literals that look like SQL (contain a SQL verb) are checked —
 * prose mentioning `wp_` stays silent.
 */
final class TablePrefixAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WP_TABLE_PREFIX';

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
            $strings = $this->finder()->find($ast, static function (Node $node): bool {
                return $node instanceof Node\Scalar\String_;
            });
            foreach ($strings as $string) {
                if (!$string instanceof Node\Scalar\String_) {
                    continue;
                }
                $value = $string->value;
                if (preg_match('/\b(select|insert|update|delete|from|join|where|create\s+table|alter\s+table|drop\s+table)\b/i', $value) !== 1) {
                    continue;
                }
                if (preg_match('/\bwp_[a-z0-9_]+/i', $value, $m) !== 1) {
                    continue;
                }
                $issues[] = $this->makeIssue(
                    self::RULE,
                    sprintf("Hardcoded table prefix '%s' — use \$wpdb->prefix instead.", $m[0]),
                    $file,
                    $string->getStartLine(),
                    Severity::Info,
                    ['table' => $m[0]],
                    Confidence::Low
                );
            }
        }

        return $issues;
    }
}
