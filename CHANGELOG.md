# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-10-27

### Added

- Readable HTML reports (ported from parent): How-to-read box, slim
  severity-only Summary, P0 fix-now table (capped at 20, collapsed),
  P-labeled quality buckets, P-split Risk columns, OWASP drill-down obeying
  severity chips, methodology appendix; WordPress branding verified end to
  end (no Laravel leftovers in output).
- `--no-remediation` flag + `remediation` config (default on): findings-only
  mode for human reports; JSON/SARIF keep full data.
- Tests `101→112`; `phpstan [OK]`, `phpcs` clean.

## [0.2.0] - 2026-10-24

### Added

- Single-file `wp-check.phar` (`tools/build-phar.php`): no Composer needed to
  run, `.sha256` sidecar, stamped version (`--version`), verified against a
  consumer install (`vendor/bin/wp-check` proxy).
- `--coverage` flag: opts into test-coverage checks for one run without
  editing config (they default off); pinned both directions by test.
- Remediation catalog (`why` + wrong/right fix) for all 8 WP rules — reports
  no longer render empty fix sections.
- Docs-pinning tests: README WP table, remediation coverage, standalone help
  flags (a new rule/flag without docs fails the build).
- Pro-grade docs: README rewrite + `docs/installation.md`, `docs/rules.md`
  (TP/FP examples per rule), `docs/ci.md`, `docs/faq.md`.

### Fixed (Yoast dogfood round)

- `prepare()` result stays clean through variables; `phpcs:ignore/disable
  WordPress.DB.*` honored as human review; hex-density ignores regex
  character classes (`[\x00-\x7F]`); obfuscated scan skips `tests/`.
- Correct submenu capability index; `--version` flag on all entry points;
  `PackageVersion` reads the fork's package name (reports said `0.0.0`).

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
