# Rampart WP Checker — Detailed Spec (Free for WordPress)

> Version: 1.0 (draft, 2026-10-23) · Status: planning, folder created, no code yet
> Parent: `rampart/quality-checker` v1.5.6 (Laravel). Strategy: **fork by copy**,
> not rewrite. Target: v0.1 demoable in 2–4 days, standalone-only.

---

## 1. Goal & non-goals

**Goal:** one-command quality gate for WordPress plugins/themes:
`php bin/wp-check /path/to/plugin` — security (SQLi/XSS/CSRF/caps), WPCS style,
broken patterns, reports HTML/JSON/SARIF, exit codes for CI.

**Non-goals (v0.1):** no WP-CLI command (standalone only), no auto-fix (except
phpcbf passthrough), no taint-by-default (reuse engine opt-in), no precision
claims without a corpus (say "heuristic" loudly).

## 2. Fork map (from `laravel-check` @ v1.5.6)

Dependency audit result: real `illuminate/*` package usage exists in exactly
**2 files**. Everything else is either framework-agnostic or Laravel *name
strings* inside analyzer logic (data, not dependencies).

| Action | Files |
|---|---|
| DELETE | `src/Commands/QualityCheckCommand.php` (artisan only), `src/QualityCheckerServiceProvider.php` (Laravel provider) |
| DELETE | `src/Analyzers/Laravel/` (migration, route validation), `src/Analyzers/Frontend/BladeStackAnalyzer.php`, Blade parts of OWASP analyzers if Laravel-coupled |
| DELETE | `tests/Feature/QualityCheckCommandTest.php`, testbench from require-dev |
| KEEP AS-IS | `src/Standalone/`, `src/Runner/`, `src/Checkers/` (all 7), `src/Reporters/` (all 5), `src/Result/`, `src/Baseline/`, `src/Suppression/`, `src/Scanning/`, `src/Semantic/` (generic parts), `src/Profiling/`, `src/Tools/`, `src/Fixer/` (phpcbf) |
| KEEP (generic rules) | SQLi/XSS/command/SSRF/traversal/redirect/XXE/SSTI analyzers, hardcoded secret, insecure hash/cookie, eval/unserialize, test-coverage heuristics, JS/CSS syntax, ops (perms/EOL), forensics (rogue/obfuscated), misconfiguration (retune paths, see §5) |
| ADD | `src/Analyzers/Wordpress/` — 8 new analyzers (§5) |
| REWRITE | `composer.json` (below), `config/wp-checker.php` (below), `bin/wp-check` (rename + default paths), `README.md`, `CHANGELOG.md` (fresh, v0.1.0) |

**Namespace decision (v0.1, deliberate):** KEEP `Rampart\QualityChecker\` namespaces
and class names. Rationale: zero-churn copy; the two packages will rarely coexist
in one vendor dir (documented limitation). Revisit only if a real conflict appears.

## 3. Package shell (`composer.json` draft)

```json
{
    "name": "rampart/wp-quality-checker",
    "description": "WordPress quality gate: WPCS, phpstan, phpunit, WP security heuristics (wpdb/nonce/capability/escaping).",
    "license": "MIT",
    "type": "library",
    "bin": ["bin/wp-check"],
    "require": {
        "php": "^8.1",
        "nikic/php-parser": "^5.0",
        "symfony/console": "^6.0|^7.0",
        "symfony/process": "^6.0|^7.0"
    },
    "require-dev": {
        "phpunit/phpunit": "^10.5|^11.0",
        "squizlabs/php_codesniffer": "^3.13",
        "wp-coding-standards/wpcs": "^3.0"
    },
    "autoload": {"psr-4": {"Rampart\\QualityChecker\\": "src/"}},
    "scripts": {
        "test": "phpunit",
        "lint": "phpcs --standard=phpcs.xml.dist src tests tools bin",
        "analyse": "phpstan analyse --memory-limit=1G --no-progress"
    }
}
```

Notes:
- Running tool needs PHP 8.1+, but it can SCAN any PHP version (php-parser
  handles 5.x–8.x syntax; WordPress.org still supports 7.x code — must not
  crash, add a PHP-5-style fixture test).
- `extra.laravel.providers` removed (no artisan). WP-CLI integration = v0.2.
- No `illuminate/*`, no `orchestra/testbench`. Tests use plain PHPUnit, same
  style as the parent's `tests/Unit/`.

## 4. Default config (`config/wp-checker.php` sketch)

```php
'paths' => ['.'],                    // plugin/theme root (single dir)
'exclude' => [],                     // checker names, merged with --exclude
'tier' => 'quality',
'min_confidence' => 'low',
'min_severity' => 'info',            // same semantics as parent v1.5.6
'phpcs' => ['standard' => 'WordPress', 'severity' => 0],
'phpstan' => ['level' => 5, 'memoryLimit' => '1G'],  // 5, not 6: WP code is looser
'phpunit' => ['testsuite' => null, 'coverageThreshold' => 60],
'analyzers' => [
    'enabled' => true,
    'security' => [ /* generic on; laravel_taint/disabled_csrf/auth_hardening OFF */ ],
    'owasp' => [ /* generic on; blade_xss OFF (no Blade) */ ],
    'wordpress' => [
        'wpdb_prepare' => true, 'unescaped_output' => true,
        'nonce' => true, 'capability' => true,
        'config_secret' => true, 'abspath' => true,
        'table_prefix' => false, 'textdomain' => false, // convention, opt-in
    ],
    'test_coverage' => ['unified' => true, /* rest off */],
    'frontend' => ['js_syntax' => true, 'css_syntax' => true],
    'ops' => ['perms' => true, 'eol' => false],  // EOL table is Laravel/PHP — PHP half reusable later
    'max_file_kb' => 1024,
    'exclude_paths' => ['*/vendor/*', '*/node_modules/*', '*/fixtures/*'],
],
'fail_on' => 'error',
'quality_gate' => ['ignore' => []],
```

## 5. The 8 WordPress analyzers (v0.1 scope)

Conventions: `$wpdb` arrives via `global $wpdb;` (track the global), AJAX via
`wp_ajax_*`/`admin_post_*` hooks, admin pages via `add_menu_page` callbacks.
Severity/confidence mirror the parent's scale. Every rule needs 5 TP + 5 TN
fixtures before it ships (mini-corpus; full 100-case corpus = v0.2).

| # | Rule id | Fires when | Severity | Must NOT fire (TN) |
|---|---|---|---|---|
| 1 | `WPDB_PREPARE` | `$wpdb->query/get_results/get_var($tainted)` where the arg is not a `$wpdb->prepare(...)` call result and not a literal | Error / Medium | `$wpdb->query($wpdb->prepare("...%d", $x))`, literals, `$wpdb->insert/update/delete` (bound by API) |
| 2 | `WP_UNESCAPED_OUTPUT` | `echo/print/<?= $tainted` outside `esc_html*()/esc_attr*()/esc_url()/esc_textarea()/wp_kses*()` wrapping | Warning / Medium | any `esc_*` wrapper, `echo esc_html__()`, numbers/casts |
| 3 | `WP_NONCE` | form handler (`admin_post_*`, `$_POST` processing in admin page callback) with no `wp_verify_nonce`/`check_admin_referer`/`check_ajax_referer` in the same function | Warning / Medium | handler calls a verify function; GET-only renderers |
| 4 | `WP_CAPABILITY` | `wp_ajax_*` (non-`nopriv`), `admin_post_*`, `add_menu_page` callback without `current_user_can` in the same function | Error / Medium | `wp_ajax_nopriv_*`, capability-checked, pure renderers returning early |
| 5 | `WP_CONFIG_SECRET` | `define('AUTH_KEY'|'SECURE_AUTH_KEY'|…|'DB_PASSWORD', '<non-empty-non-placeholder>')` in `wp-config*.php`, or keys shorter than 32 chars | Warning / Medium | `put your unique phrase here` placeholders, empty values in `.example` |
| 6 | `WP_ABSPATH` | top-level plugin PHP file (`Plugin Name:` header) without `defined('ABSPATH') || exit` guard | Warning / High | guarded files, non-plugin files |
| 7 | `WP_TABLE_PREFIX` | raw `wp_` table name in SQL strings instead of `$wpdb->prefix` (convention) | Info / Low | `$wpdb->prefix` usage, non-SQL strings |
| 8 | `WP_TEXTDOMAIN` | `__()/ _e()/esc_html__()` without text domain or with variable domain (convention) | Info / Low | correct `__('s', 'domain')` |

Out of scope v0.1 (documented, not silently missing): transients/options autoload
bloat, cron abuse, `eval` in themes (covered by generic `UNSAFE_EVAL`), updater
integrity, multisite checks → v0.2 candidates.

## 6. Reuse contracts (do not fork behavior)

- Exit codes `0/1/2/3`, `--fail-on`, `--min-confidence`, `--min-severity`,
  `--ignore`, `--exclude-path`, baseline files, inline suppression comments,
  SARIF/JSON/HTML/MD/console reporters: byte-compatible semantics with parent.
- `SKILL.md`: copy and replace Laravel specifics (keep agent-native style).
- Pro compatibility: Pro taint/fixer/gate read Free JSON shapes — WP JSON uses
  the identical schema, so `rampart-pro` works against WP reports unchanged.

## 7. Test plan

- **Unit:** each WP analyzer ships with its 10 fixtures (5 TP + 5 TN) following
  parent's `tests/Unit/*AnalyzerTest.php` style; plus a PHP-5-syntax fixture
  (old `array()`, no scalar hints) proving the parser path never crashes.
- **Contract:** `RuleIds`-equivalent registry test (new rule ids documented in
  README tables or build fails) — copy the parent's `RuleIdsTest`/`RuleDocsTest`
  pattern with the WP rule set.
- **Smoke (v0.1 gate):** scan bundled plugins `akismet` + `hello-dolly` (ship
  with WordPress core) + one popular plugin pinned (Contact Form 7, version
  pinned in the test): must finish <5 min, 0 crashes, findings eyeballed once
  for FP storms (orderBy-style lesson from the parent: tune before claiming).
- **No precision claims in v0.1.** Full 100-case corpus + holdout = v0.2
  acceptance (target: high-rule precision ≥0.9, same bar as parent 7/8).

## 8. Milestones

- **Day 1:** fork copy, delete per §2, composer.json + bin/wp-check + config boot
  (scan `hello-dolly` end-to-end, any output = win).
- **Day 2:** analyzers 1–4 (wpdb, escaping, nonce, capability) + 40 fixtures.
- **Day 3:** analyzers 5–8 + README rule tables + SKILL.md + smoke (akismet/CF7).
- **Day 4 (buffer):** FP triage on smoke results, `--min-severity` recipes for
  plugin authors, tag `v0.1.0`, Packagist submit.
- **v0.2 (following week):** 100-case corpus, taint sources WP (`$_GET` →
  `$wpdb`/options/echo), WPCS auto-fix passthrough, WP-CLI command sketch.

## 9. Risks (stated upfront, not discovered later)

1. **FP storm on `esc_*` family:** mitigated by whitelisting the full escaping
   family (not just `esc_html`), numbers/casts silent, tests pin each TN.
2. **Global-function style:** `$wpdb` via `global`, hooks via `add_action` strings
   — analyzers must resolve string callback names (`'my_handler'`), not just
   closures. Fixture for both forms.
3. **Old PHP in targets:** scanner runs on PHP 8.1+ but must parse PHP 5.x;
   php-parser does — pinned by the PHP-5 fixture test.
4. **No-Server updates, no auto-update:** v0.1 is a point tool, not a SaaS.
5. **Rule-table drift:** README tables enforced by tests (parent pattern), so
   docs cannot rot.

## 10. Naming (locked for v0.1)

Package `rampart/wp-quality-checker` · binary `bin/wp-check` · config
`config/wp-checker.php` · PHP namespace kept `Rampart\QualityChecker\`
(deliberate, documented in §2) · first tag `v0.1.0` · license MIT.
