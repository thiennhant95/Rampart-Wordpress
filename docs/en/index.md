---
layout: default
title: Rampart WP Checker — English
description: Full English documentation for the WordPress quality gate.
---

# Rampart WP Checker — English

[English]({{ site.baseurl }}/en/) · [日本語]({{ site.baseurl }}/ja/) · [Tiếng Việt]({{ site.baseurl }}/vi/)

Quality gate for **WordPress plugins and themes** on **PHP 8.1+**. Static
analysis, no WordPress runtime, no account, no telemetry. The tool scans code;
it never boots WordPress.

## The Rampart family

| Project | Focus | Repository | Documentation |
|---|---|---|---|
| **Rampart** | Laravel Artisan quality gate | [GitHub](https://github.com/thiennhant95/Rampart) | [Pages](https://thiennhant95.github.io/Rampart/) |
| **Rampart PHP** | Plain PHP + frameworks, PHP 7.4+ | [GitHub](https://github.com/thiennhant95/Rampart-PHP) | [Pages](https://thiennhant95.github.io/Rampart-PHP/) |
| **Rampart WP** | WordPress plugins/themes | [GitHub](https://github.com/thiennhant95/Rampart-Wordpress) | [Pages](https://thiennhant95.github.io/Rampart-Wordpress/) |

## Install

**From source (works today):**

```bash
git clone https://github.com/thiennhant95/Rampart-Wordpress.git
cd Rampart-Wordpress
composer install --no-interaction
php bin/wp-check /path/to/plugin
```

**Phar:** download `wp-check.phar` and `wp-check.phar.sha256` from GitHub
Releases, verify the checksum, then run `php wp-check.phar --version`.

**Composer via GitHub:**

```bash
composer config repositories.wp-checker vcs https://github.com/thiennhant95/Rampart-Wordpress
composer require --dev rampart/wp-quality-checker:^0.4
php vendor/bin/wp-check /path/to/plugin
```

Requirements: PHP 8.1+ to run the tool. The scanned plugin may target any PHP
version supported by WordPress.

## Quick start

```bash
php bin/wp-check /path/to/my-plugin --min-severity=error --fail-on=none --format=html
```

The report is written to `reports/wp-checker/`. Exit codes: `0` clean/review ·
`1` gate failed · `2` bad invocation · `3` internal error.

## What it checks

Wrapped tools: WPCS (`phpcs` with WordPress rules), `phpstan`, `phpunit`.

WordPress rules:

| Rule | Severity | Fires when |
|---|---|---|
| `WPDB_PREPARE` | error | `$wpdb->query/get_results/...` with dynamic SQL and no `$wpdb->prepare()` |
| `WP_UNESCAPED_OUTPUT` | warning | `echo`/`print`/`<?=` without `esc_*()`/`wp_kses()`/cast |
| `WP_NONCE` | warning | `admin_post_*`/`wp_ajax_*` handler without nonce verification |
| `WP_CAPABILITY` | error | privileged handler without `current_user_can()` |
| `WP_CONFIG_SECRET` | warning | empty/placeholder/short secret in `wp-config*.php` |
| `WP_ABSPATH` | warning | plugin entry file without `defined('ABSPATH')` |
| `WP_TABLE_PREFIX` | info (opt-in) | literal `wp_` table in SQL |
| `WP_TEXTDOMAIN` | info (opt-in) | translation call without a literal domain |

Generic inherited rules (SQLi, XSS, command injection, SSRF, secrets, eval,
JS/CSS syntax, ...) behave like the parent Rampart analyzers. Full examples:
[rules.html]({{ site.baseurl }}/rules.html).

## Reports

`console`, `json`, `html`, `md`, `sarif`. HTML variants: developer,
findings-only (`--no-remediation`), client (`--client`), or all three
(`--all-variants`). Machine reports always carry the full data.
See [reports.html]({{ site.baseurl }}/reports.html).

## CI

```yaml
- run: composer install --prefer-dist --no-progress
- run: php bin/wp-check . --min-severity=error --fail-on=error --format=json
```

For GitHub code scanning, add `--format=sarif` and upload the SARIF file.
Recipes: [ci.html]({{ site.baseurl }}/ci.html).

## Baseline

```bash
php bin/wp-check /path/to/plugin --baseline-generate --fail-on=none
php bin/wp-check /path/to/plugin --baseline-file=baseline.json
```

Inline suppression follows the shared Rampart syntax:
`// rampart-ignore RULE_ID` or `// rampart-ignore-next-line RULE_ID`
(the legacy `quality-checker-ignore` alias is accepted).

## Limits

- Heuristics, not proof. Read the file:line before fixing or suppressing.
- `call_user_func($dynamic)` behind `is_callable` guards may still flag.
- Standalone only: the tool scans, it never boots WordPress.

FAQ: [faq.html]({{ site.baseurl }}/faq.html). Install options: [installation.html]({{ site.baseurl }}/installation.html).

## License

MIT © 2026 Rampart.
