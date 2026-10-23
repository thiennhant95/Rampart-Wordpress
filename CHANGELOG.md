# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `--coverage` flag: opts into test-coverage checks for one run without
  editing config (they default off).
- Standalone help now says `bin/wp-check` (was the parent's binary name).

## [0.1.0] - 2026-10-23

### Added (initial release — fork of rampart/quality-checker v1.5.6)

- Standalone `bin/wp-check` for WordPress plugins/themes (no Laravel runtime,
  no `illuminate/*`): same engine, reporters, exit codes, baseline, cache,
  `--ignore`, `--min-severity`, `--exclude-path` as the parent.
- 8 WordPress analyzers, each with 5 TP + 5 TN fixtures (80 cases):
  `WPDB_PREPARE`, `WP_UNESCAPED_OUTPUT`, `WP_NONCE`, `WP_CAPABILITY`,
  `WP_CONFIG_SECRET`, `WP_ABSPATH`, `WP_TABLE_PREFIX` (opt-in),
  `WP_TEXTDOMAIN` (opt-in).
- Defaults tuned for WordPress: WPCS standard, phpstan level 5, Laravel-only
  engines off, `vendor/`/`node_modules/` excluded, flat plugin roots scanned.
- Fork fixes from dogfood: `$wpdb->*` table properties never tainted, literal
  temps/ternaries silent in unescaped-output, hex-density ignores whitespace
  escapes, flat-root scan fallback, correct submenu capability index.
- Docs: README (rules table, severity guide, recipes), SKILL.md, SPEC.md.
- `87` tests green, `phpstan` level 6 `[OK]`, `phpcs` clean.
- Smoke (no crashes): hello-dolly clean, akismet 31, contact-form-7 46 —
  eyeballed; known heuristic limits documented in README.

### Known limits (v0.1, no precision claims)

- `call_user_func($dynamic)` flags behind `is_callable` guards; `include`
  with `basename()` still flags; unknown function params are clean
  (cross-function taint = v0.2 with the 100-case corpus + holdout).
