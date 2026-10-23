# Rampart WP Checker

One-command quality gate for WordPress plugins and themes (free, MIT).
Forked from `rampart/quality-checker` (Laravel) — same engine, reports and
exit codes, WordPress rule set. Status: **v0.1.0-dev, not on Packagist yet**
(see SPEC.md §8 for the release checklist).

## Requirements

- PHP 8.1+ and Composer to **run** the tool. It can **scan** any PHP version
  (WordPress 6.x still supports PHP 7.x code — covered by a fixture test).

## Install (for now: clone)

```bash
git clone <repo> wp-checker && cd wp-checker
composer install --no-interaction
```

Packagist (`composer require rampart/wp-quality-checker`) lands with v0.1.0.

## Quick start (5 minutes)

```bash
# Calm first run: errors and criticals only, both formats
php -d memory_limit=1G bin/wp-check /path/to/plugin \
  --min-severity=error --fail-on=none --format=json,html \
  --output=reports/wp-checker

# Deep audit: everything, then triage
php -d memory_limit=1G bin/wp-check /path/to/plugin \
  --fail-on=none --format=all --output=reports/wp-checker
```

## How to read a report

| Severity | Meaning | What to do |
|---|---|---|
| `critical` | Exploitable now (injection, eval, LFI-shaped include). | Fix before release. |
| `error` | Real bug/security hole, confirm by reading the file. | Fix this sprint. |
| `warning` | Suspicious pattern, often safe (unescaped `echo`, missing nonce). | Backlog. Do not panic. |
| `info` | Convention FYI (prefix, text domain). Never fails the build. | Read if curious. |

## WordPress rules (v0.1)

| Rule | Severity | On by default | Fires when |
|---|---|---|---|
| `WPDB_PREPARE` | error | yes | `$wpdb->query/get_results/get_var/get_row/get_col` with dynamic SQL and no `$wpdb->prepare()` |
| `WP_UNESCAPED_OUTPUT` | warning | yes | `echo/print/<?=` without `esc_*()`/`wp_kses()`/cast (WPCS semantics) |
| `WP_NONCE` | warning | yes | `admin_post_*`/`wp_ajax_*` handler without nonce verification |
| `WP_CAPABILITY` | error | yes | privileged handler without `current_user_can()` (literal `add_*_page` capability counts as checked) |
| `WP_CONFIG_SECRET` | warning | yes | empty/placeholder/short secret in `wp-config*.php` (samples skipped) |
| `WP_ABSPATH` | warning | yes | plugin entry file without `defined('ABSPATH')` guard |
| `WP_TABLE_PREFIX` | info | no | literal `wp_` table in SQL strings |
| `WP_TEXTDOMAIN` | info | no | translation call without literal domain |

Generic inherited rules (SQLi, XSS, command injection, SSRF, traversal,
secrets, eval, JS/CSS syntax, perms, rogue/obfuscated PHP, …) behave exactly
like the parent — see its README for that reference.

## Options (short)

| Option | Effect |
|---|---|
| `--min-severity=error` | Hide warnings/info from reports (gate still uses `--fail-on`). Use when reports alarm readers. |
| `--ignore=RULE,...` | Skip whole rules, e.g. `--ignore=WP_UNESCAPED_OUTPUT`. Dropped from reports, not just the gate. |
| `--fail-on=none` | Review without failing (exit 0). |
| `--exclude-path='*/vendor/*'` | Skip paths (repeatable). `vendor`/`node_modules`/`fixtures` already excluded. |
| `--baseline-generate` / `--baseline-file` | Accept current findings; fail only on new ones after. |

## Known limits (v0.1, stated not discovered)

- Heuristics, not proof: `call_user_func($dynamic)` flags even behind
  `is_callable` guards; `include` with `basename()` still flags; unknown
  function params are treated as clean (cross-function taint = v0.2).
- No precision claims yet: the 100-case corpus + holdout lands in v0.2.
- Standalone only (no WP-CLI command). Scans code, never boots WordPress.

## Troubleshooting

**Too many findings on the first run?** Start with
`--min-severity=error`, then `--ignore` whole noisy rules temporarily, then
`--baseline-generate` and fail only on new findings.

**A finding looks wrong?** Read the file:line, check the TN column in
SPEC.md §5 for the rule's documented blind spots, then baseline it with a
comment or `--ignore` — do not fork the analyzer per project.

## License

MIT.
