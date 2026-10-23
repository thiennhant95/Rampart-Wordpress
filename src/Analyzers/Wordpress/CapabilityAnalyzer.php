<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WP_CAPABILITY — privileged handlers without a capability check.
 *
 * Scope: callbacks of `wp_ajax_*` (excluding `wp_ajax_nopriv_*`),
 * `admin_post_*`, and the `add_*_page` family (`add_menu_page`,
 * `add_submenu_page`, `add_options_page`, `add_dashboard_page`, …) whose body
 * never calls `current_user_can`, `user_can` or `author_can`.
 *
 * Precision rule that matters: `add_menu_page($title, $menu, $capability, …)`
 * with a non-empty literal capability (index 2) is enforced by WordPress core
 * itself — those callbacks are silent. Only string callbacks are resolved.
 */
final class CapabilityAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WP_CAPABILITY';

    /** @var list<string> */
    private const CAP_FUNCS = ['current_user_can', 'user_can', 'author_can'];

    /** @var list<string> */
    private const PAGE_FUNCS = [
        'add_menu_page', 'add_submenu_page', 'add_options_page',
        'add_dashboard_page', 'add_posts_page', 'add_media_page',
        'add_links_page', 'add_pages_page', 'add_comments_page',
        'add_theme_page', 'add_plugins_page', 'add_users_page',
        'add_management_page',
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
            foreach ($this->handlerRegistrations($ast, $file) as [$hook, $kind, $name, $line]) {
                $func = WpHooks::findCallable($ast, $kind, $name, $this->finder());
                if ($func === null) {
                    continue;
                }
                if (WpHooks::bodyCalls($func, self::CAP_FUNCS, $this->finder())) {
                    continue;
                }
                $issues[] = $this->makeIssue(
                    self::RULE,
                    sprintf("Handler '%s' for '%s' never checks capabilities — add current_user_can().", $name, $hook),
                    $file,
                    $func->getStartLine(),
                    Severity::Error,
                    ['hook' => $hook, 'callback' => $name],
                    Confidence::Medium
                );
            }
        }

        return $issues;
    }

    /**
     * [hook, kind, callback, line] for every resolvable privileged registration.
     *
     * @param list<Node> $ast
     * @return list<array{string, string, string, int}>
     */
    private function handlerRegistrations(array $ast, string $file): array
    {
        $out = [];
        foreach (WpHooks::findCalls($ast, array_merge(['add_action'], self::PAGE_FUNCS), $this->finder()) as $call) {
            $fname = $call->name instanceof Node\Name ? strtolower($call->name->toString()) : '';
            if ($fname === 'add_action') {
                $hook = WpHooks::firstStringArg($call);
                if ($hook === null || str_starts_with($hook, 'wp_ajax_nopriv_')) {
                    continue;
                }
                if (!str_starts_with($hook, 'wp_ajax_') && !str_starts_with($hook, 'admin_post_')) {
                    continue;
                }
                $resolved = WpHooks::resolveCallback($call, 1);
                if ($resolved === null) {
                    continue;
                }
                $out[] = [$hook, $resolved[0], $resolved[1], $call->getStartLine()];
                continue;
            }
            // Capability position differs: add_submenu_page(parent, title,
            // menu, capability, slug, callback) carries it at index 3, the
            // rest at index 2. A literal non-empty capability is
            // core-enforced — silent.
            $args = $call->getArgs();
            $capArg = $args[$fname === 'add_submenu_page' ? 3 : 2] ?? null;
            if ($capArg instanceof Node\Arg && $capArg->value instanceof Node\Scalar\String_ && trim($capArg->value->value) !== '') {
                continue;
            }
            $cbIndex = $this->callbackIndex($fname);
            $resolved = WpHooks::resolveCallback($call, $cbIndex);
            if ($resolved === null) {
                continue;
            }
            $out[] = [$fname, $resolved[0], $resolved[1], $call->getStartLine()];
        }

        return $out;
    }

    /**
     * add_menu_page / add_submenu_page carry the callback at index 4,
     * most others at index 4 as well (title, menu, cap, slug, callback).
     * add_submenu_page: parent, title, menu, cap, slug, callback → 5.
     */
    private function callbackIndex(string $fname): int
    {
        return $fname === 'add_submenu_page' ? 5 : 4;
    }
}
