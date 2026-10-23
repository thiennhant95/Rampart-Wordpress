# Rampart WP Checker — Agent Skill

Use this skill when you need a WordPress quality/security gate.

## When to use
- Before releasing a plugin/theme version, or in CI on every push.
- When the user asks for WPCS, `wpdb`, nonce/capability audit, or a WP scan.

## Commands

### Calm first run (recommended)
```bash
php -d memory_limit=1G bin/wp-check /path/to/plugin --min-severity=error --fail-on=none --format=json,html --output=reports/wp-checker
```

### Deep audit
```bash
php -d memory_limit=1G bin/wp-check /path/to/plugin --fail-on=none --format=all --output=reports/wp-checker
```

### Quiet a noisy rule (temporary)
```bash
php bin/wp-check /path --ignore=WP_UNESCAPED_OUTPUT --fail-on=none
```

### Baseline a legacy plugin, fail only on new findings
```bash
php bin/wp-check /path --baseline-generate --fail-on=none
php bin/wp-check /path --baseline-file=baseline.json
```

## Outputs
- `quality-report.html` — self-contained, searchable, code snippets
- `quality-report.json` — machine-readable for CI
- `quality-report.sarif` — GitHub code scanning
- Exit `0` passed/clean review, `1` failed gate, `2` bad invocation, `3` internal error.

## Gate
- Fix `critical` before release, `error` this sprint, `warning` is backlog.
- A count with mostly warnings is normal on first scan — not N vulnerabilities.

## Notes
- Requires PHP 8.1+ to run; scans any PHP version (incl. 5.x-era plugins).
- `vendor/`/`node_modules/` excluded by default.
- Heuristics, no precision claims in v0.1 — verify criticals by reading code.
