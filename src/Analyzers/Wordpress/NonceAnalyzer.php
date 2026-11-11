<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WP_NONCE — state-changing handlers without nonce verification.
 *
 * Scope (precise): functions registered via `add_action('admin_post_*')`
 * or `add_action('wp_ajax_*')` (excluding `wp_ajax_nopriv_*`) whose body never
 * calls `wp_verify_nonce`, `check_admin_referer`, `check_ajax_referer` or
 * `wp_nonce_ays`. Only string callbacks (`'func'`, `'Class::method'`) are
 * resolved; closures and array callables are skipped, never guessed.
 * GET-only renderers and nopriv handlers are out of scope by design.
 */
final class NonceAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WP_NONCE';

    /** @var list<string> */
    private const VERIFY_FUNCS = ['wp_verify_nonce', 'check_admin_referer', 'check_ajax_referer', 'wp_nonce_ays'];

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
            foreach (WpHooks::findCalls($ast, ['add_action'], $this->finder()) as $call) {
                $hook = WpHooks::firstStringArg($call);
                if ($hook === null) {
                    continue;
                }
                if (str_starts_with($hook, 'wp_ajax_nopriv_')) {
                    continue;
                }
                $stateful = str_starts_with($hook, 'admin_post_') || str_starts_with($hook, 'wp_ajax_');
                if (!$stateful) {
                    continue;
                }
                $resolved = WpHooks::resolveCallback($call, 1);
                if ($resolved === null) {
                    continue;
                }
                [$kind, $name] = $resolved;
                $func = WpHooks::findCallable($ast, $kind, $name, $this->finder());
                if ($func === null) {
                    continue;
                }
                if (WpHooks::bodyCalls($func, self::VERIFY_FUNCS, $this->finder())) {
                    continue;
                }
                $issues[] = $this->makeIssue(
                    self::RULE,
                    sprintf("Handler '%s' for '%s' never verifies a nonce — add wp_verify_nonce()/check_admin_referer().", $name, $hook),
                    $file,
                    $func->getStartLine(),
                    Severity::Warning,
                    ['hook' => $hook, 'callback' => $name],
                    Confidence::Medium
                );
            }
        }

        return $issues;
    }
}
