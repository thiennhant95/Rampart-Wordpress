<?php

return [
    // WordPress plugin/theme root: scan everything, pruning vendor and
    // node_modules via analyzers.exclude_paths below.
    'paths' => ['.'],

    // Checkers to skip, e.g. ['phpstan', 'composer_audit']. Merged with
    // --exclude, so neither source overrides the other. Paths to skip go in
    // analyzers.exclude_paths below, not here.
    'exclude' => [],

    // Self-provision missing tools: phpcs/phpstan/phpunit via composer, trivy
    // via a cached binary download. Off by default: this runs `composer require
    // --dev` inside your project, which edits composer.json and vendor/ before
    // you have seen a single finding, and there is no undo. Turn it on once you
    // have decided you want it. Disable for air-gapped/read-only projects.
    'auto_install_tools' => false,

    // Gate tier. 'security' fails only on security issues (high confidence);
// 'quality' and 'all' add the phpcs/phpstan/phpunit errors. Checkers that
    // produce no quality-gate signal (dependency audit, trivy, the heuristic
    // analyzers) report the same in all three tiers — use 'exclude' or
    // --only to skip them.
    'tier' => 'quality',

    // Minimum confidence to report. Heuristics below this are hidden.
    'min_confidence' => 'low',

    // Minimum severity to report. Below this is hidden from reports (but the
    // gate still uses --fail-on independently): use 'error' to stop light
    // warnings and info findings from alarming readers.
    'min_severity' => 'info',

    'phpcs' => [
        'standard' => 'WordPress',
        'severity' => 0,
    ],

    'phpstan' => [
        'level' => 5,
        'memoryLimit' => '1G',
    ],

    'phpunit' => [
        'testsuite' => null,
        'coverageThreshold' => 60,
    ],

    'composer_audit' => [
        'enabled' => true,
    ],

    'npm_audit' => [
        'enabled' => true,
    ],

    'trivy' => [
        'enabled' => false,
        'mode' => 'config',
        'binary' => 'trivy',
        'version' => '0.74.0',
    ],

    // Result cache for checker runs (file-based under the output directory).
    'cache' => [
        'enabled' => true,
        'ttl' => 3600,
    ],

    'analyzers' => [
        'enabled' => true,

        // Inline per-finding suppression via `// quality-checker-ignore RULE`
        // (same line) or `// quality-checker-ignore-next-line RULE`.
        'inline_suppression' => true,

        // Security rules (high/medium confidence) — on by default.
        // Laravel-only engines are OFF: mass_assignment (Eloquent),
        // laravel_taint, disabled_csrf (FormRequest), auth_hardening
        // (session rotation). WordPress equivalents live under 'wordpress'.
        'security' => [
            'sql_injection' => true,
            'unsafe_eval' => true,
            'hardcoded_secret' => true,
            'mass_assignment' => false,
            'unsafe_unserialize' => true,
            'insecure_hash' => true,
            // Programmatic cookies without an explicit Secure flag.
            'insecure_cookie' => true,
            'auth_hardening' => false,
            'laravel_taint' => false,
            'disabled_csrf' => false,
            'rogue_php' => true,
            'obfuscated' => true,
            'taint_engine' => false,
        ],

        // OWASP Top 10 — high confidence. blade_xss is off (no Blade in
        // WordPress; see wordpress.unescaped_output instead). ownership_idor
        // models Laravel ownership — off until a WP equivalent exists.
        'owasp' => [
            'broken_access_control' => true,
            'route_middleware' => true,
            'blade_xss' => false,
            'open_redirect' => true,
            'path_traversal' => true,
            'ssrf' => true,
            'ssti' => true,
            'misconfiguration' => true,
            'command_injection' => true,
            'xxe' => true,
            'ownership_idor' => false,
        ],

        // Test-coverage heuristics — ALL opt-in (off by default). Missing-test
        // findings are backlog noise on most codebases, so they never run
        // unless asked: set 'unified' => true (or a single rule) to opt in.
        'test_coverage' => [
            // Unified missing-test detector (controller/service/repository/model, unit+feature).
            'unified' => false,
            'missing_controller_test' => false,
            'missing_service_test' => false,
            'missing_model_test' => false,
            'missing_feature_coverage' => false,
            'test_without_assert' => false,
        ],

        // WordPress-specific checks — the v0.1 WP analyzer set (SPEC.md §5).
        'wordpress' => [
            'wpdb_prepare' => true,
            'unescaped_output' => true,
            'nonce' => true,
            'capability' => true,
            'config_secret' => true,
            'abspath' => true,
            // Convention-grade, opt-in (off) to avoid noise.
            'table_prefix' => false,
            'textdomain' => false,
        ],

        // Convention heuristics — low confidence, off by default (opt-in).
        'convention' => [
            'naming_convention' => false,
            'todo_fixme' => false,
            'dead_code' => false,
            'laravel_pitfall' => false,
        ],

        // Frontend heuristics — JS/CSS syntax (Warning/Medium: likely typo,
        // not a security gate).
        'frontend' => [
            'js_syntax' => true,
            'css_syntax' => true,
        ],

        // Ops checks — perms on; eol on (PHP half of the table applies to
        // WordPress, the laravel/framework half is skipped when absent).
        'ops' => [
            'perms' => true,
            'eol' => true,
        ],

        // Additional protective middleware name fragments for access control.
        'extra_middleware' => [],

        'extra_sanitizers' => [],

    // Per-rule severity remapping, applied after analysis. The Blade override
    // below is inert on WordPress (no Blade templates) and kept so the key
    // stays valid if the analyzer is ever re-enabled.
        'severity_overrides' => [
        'OWASP_BLADE_DYNAMIC_INCLUDE' => 'info',
        ],

    // Skip PHP files larger than this (multi-MB data dumps exhaust the
    // parser with no signal). 0 or negative disables the limit.
        'max_file_kb' => 1024,

    // Paths the custom analyzers never look at. `analyzers.exclude` above
    // filters checker names, not files, so this is the only way to keep the
    // analyzers out of a directory. Fixtures are excluded by default: code
    // under a `fixtures/` directory exists to be vulnerable (test samples,
    // security demos), and reporting it is noise, not signal. Accepts globs
    // (`*/fixtures/*`, matched against the whole path) or plain segment
    // sequences (`tests/fixtures`, matched on `/` boundaries), both
    // case-insensitive. Matching files are counted in the checker summary, so
    // nothing is hidden silently. Override freely: `[]` scans everything.
        'exclude_paths' => ['*/vendor/*', '*/node_modules/*', '*/fixtures/*'],
    ],

    'fail_on' => 'error',

    // Quality gate: rules listed here never fail the gate and are hidden
    // from reports (e.g. ['MISSING_MODEL_TEST']). Same as --ignore=.
    'quality_gate' => [
        'ignore' => [],
    ],

    'output_dir' => 'reports/wp-checker',

    // HTML report extras. Set repo_url after publishing (or via
    // config/wp-checker.php) to get GitHub blob links; otherwise the
    // report uses vscode:// deep links.
    'html' => [
        'repo_url' => null, // e.g. https://github.com/org/repo
        'branch' => 'main',
        // Context lines rendered around each finding (0 = disable snippets).
        'code_context' => 3,
    ],
];
