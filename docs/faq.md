# Troubleshooting & FAQ

## Too many findings on the first run

Normal. Start calm and tighten later:

1. `--min-severity=error` — errors and criticals only.
2. `--ignore=WP_UNESCAPED_OUTPUT` — hide a whole noisy rule temporarily.
3. `--baseline-generate` — accept the rest, fail only on new findings.

## A finding looks wrong

Read the `file:line`. Check the rule's blind spots in
[`docs/rules.md`](rules.md) (e.g. unknown function params are clean,
closures unresolvable). If it is a false positive, baseline it with a comment
or `--ignore` — do not fork the analyzer per project. Genuine FP patterns with
a minimal reproducer are welcome as issues.

## "0 issues" — did it even scan?

Check the summary line: `Standalone scan: N issue(s) in <dir>`. If your plugin
is a flat directory and N looks wrong, list what was collected with
`--profile` (writes `profile.json` next to reports). `vendor/`,
`node_modules/` and `fixtures/` are excluded by default.

## Out of memory on huge projects

```bash
php -d memory_limit=2G wp-check.phar /path --exclude-path='*/build/*'
```

## FAQ

**Does it modify my code?** No. Read-only scans; reports go to the output dir
(or `--output`). No auto-fix yet (phpcbf passthrough exists upstream).

**Does it phone home?** No. No account, no telemetry, no network calls. The
only network in the whole workflow is you downloading the phar.

**WordPress 6.x on PHP 7.4 — can it scan my plugin?** Yes. The tool needs
PHP 8.1+ to *run*, but parses any PHP version.

**Why is `echo $title` flagged? It's from the database!** WordPress prints
template data raw; escaping happens (or must happen) at output. That is the
WPCS rule too — wrap it in `esc_html()` and both tools go quiet.

**Why isn't `DB::select($x)` with a variable flagged as... wait, this is
WordPress — where is `$wpdb->prepare`?** Exactly: use `$wpdb->prepare()` with
`%d`/`%s` placeholders; the `WPDB_PREPARE` rule goes quiet.

**Packagist?** Pending submit. Meanwhile: phar (recommended) or source clone.

**Can I use this on wordpress.org plugins directory submissions?** It is a
dev/CI tool, not a wordpress.org plugin — it cannot be installed from the WP
admin and was never submitted there. Scan locally, fix, then submit your plugin.
