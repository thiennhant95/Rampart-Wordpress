# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.5.2] - 2026-11-25

### Fixed

- WordPress rules and the shared security/OWASP analyzers skip test paths by
  default, so fixture plugins are not reported as production sinks.
- `OWASP_SSRF` no longer flags paths rooted at `__DIR__`/`__FILE__`/`dirname()`.
- `OWASP_PATH_TRAVERSAL` recognises WordPress path helpers
  (`plugin_dir_path`, `get_template_directory`, `get_stylesheet_directory`,
  `get_theme_root`, `content_url`, `includes_url`, `plugin_basename`).
- `call_user_func` callables guarded by `is_callable()` or
  `instanceof \Closure` in the same function are treated as internally
  registered.

### Added

- CI workflow: PHP 8.1–8.4 matrix plus Windows, lint/phpstan/phpunit and a
  self-scan job.

### Changed

- The standalone CLI raises the PHP memory floor to 512M for large plugins.
- README now documents project status, supported versions and scope.

## [0.5.1] - 2026-11-25

### Fixed

- `UNSAFE_UNSERIALIZE` now matches function names case-insensitively, resolves
  simple `$f = 'unserialize'; $f(...)` indirection, and follows
  `call_user_func('unserialize', ...)` wrappers.
- `UNSAFE_EVAL` resolves simple variable function names and flags
  `call_user_func('assert', $code)` targets instead of skipping the literal
  callable.
- Malformed PHP no longer drops the whole file: the parser recovers the AST
  around syntax errors, so valid sinks elsewhere in the file are still checked.
- `OBFUSCATED_PHP` reports the matched source line, covers decode-family calls
  (`base85_decode`, `str_rot*`, `*_decode`) and malformed `eval[...]`, while
  preserving the WordPress hex-density tuning.
- `WEAK_PASSWORD_POLICY` checks both ternary branches, resolves literal
  concatenation/variables, and reports an unverifiable dynamic rule beside a
  valid literal one instead of silently trusting it.

## [0.5.0] - 2026-11-20

### Added

- `MISSING_COMPOSER` and `INVALID_CONSTRAINT`: a Composer-managed
  plugin/theme (`vendor/`, lock file, or console binary) with no
  `composer.json`, or a manifest that is unparseable / carries a non-string
  `php` or `laravel/framework` constraint.
- Shared `PhpPayloadPatterns` detector backing `OBFUSCATED_PHP` and the
  rogue-upload grading, preserving the WordPress hex tuning (whitespace
  escapes and regex character classes stay silent; floor of 6).

### Changed

- `ROGUE_PHP` now targets `wp-content/uploads` (plus Bedrock-style
  `web/wp-content/uploads` and `public/wp-content/uploads`) and grades by
  content: obfuscation/code-execution evidence → Critical; a benign stray
  file → Warning. The hardening `index.php` stays silent.
- `PERMS_TOO_OPEN` compares permission bits instead of a hardcoded octal list
  and checks `.env`, `wp-config.php` (including `web/`) and the uploads
  directory: other-write → Critical, group-write → Warning.
- `EOL_COMPONENT` reports lines older than the tracked map with a generic
  "older than X" message, and stays silent for open ranges (`>=`) and any
  supported line.

### Fixed

- Self-scan: `WP_ABSPATH` no longer flags `AbspathAnalyzer.php` itself (the
  detector's docblock/needle contain the `Plugin Name:` token).
- Console output no longer claims `✔ All checks passed.` when checkers reported findings within the `--fail-on` threshold — it now says so explicitly. `phpunit.xml.dist` no longer declares a missing `tests/Feature` suite.

## [0.4.1] - 2026-11-13

### Added

- Real wp-check security-scan screenshot in the README and the EN/JA/VI docs pages.

### Fixed

- Stale version labels in user-facing docs and comments (`v0.1`/`v0.2`
  section tags, `v0.1.0`/`v0.2` promises): headings, install/version
  examples (now `v0.4.0`), Composer constraint (`^0.3` → `^0.4`), and
  timeless wording for the planned labeled corpus and taint engine.
- Install docs: dead `windows.php.net` reference → `php.net/downloads`,
  real repo URL in the source-clone recipe.
- PHPCS: documented CLI-entry-script exclusion for the
  `PSR1.Files.SideEffects` sniff on `tools/build-phar.php`; lint is now
  warning-free (exit 0).

## [0.4.0] - 2026-11-03

### Added

- `--client`: client-facing HTML (findings only, trimmed chrome, relative
  paths, no editor deep links); writes `quality-report-client.html`.
- `--all-variants`: write dev, findings-only and client HTML in one run.
- Client branding config: `html.client_brand` and `html.client_project`.

### Changed

- Client report keeps Summary, How to read, Fix now, Quality Score, Action
  Plan, findings and OWASP (when present); drops Risk Overview, Delta, Top
  Rules, Per-Checker and Appendix.
- `--no-remediation` now also writes `quality-report-findings.html` alongside
  the legacy `quality-report.html`.
- Ported the parent's readable-report fixes (clean UTF-8 output, no Laravel
  leftovers) and fixed one pre-existing PSR-12 blank line.

### Tests

- `118` tests; PHPStan `[OK]`, PHPCS error-free (one pre-existing warning in
  `tools/build-phar.php`).

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
