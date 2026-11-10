# HTML reports

One scan can produce three HTML presentations. These options do not remove
findings, change severity counts, change the Quality Score, or change the
pass/fail gate. They only change how much fix guidance and engineering detail
the HTML page shows. JSON and SARIF always contain the complete machine data.

## Choose the file for the reader

| If the reader will ... | Add this flag | Open or send this file |
|---|---|---|
| Fix the issues | None; this is the default | `quality-report.html` |
| Review the same technical report without fix boxes | `--no-remediation` | `quality-report-findings.html` |
| Read a simplified external summary | `--client` | `quality-report-client.html` |
| Compare or archive every presentation | `--all-variants` | All three files |

Start from a normal review command and add one flag:

```bash
php wp-check.phar /path/to/plugin --format=html --fail-on=none
php wp-check.phar /path/to/plugin --format=html --fail-on=none --client
php wp-check.phar /path/to/plugin --format=html --fail-on=none --all-variants
```

From a source checkout, replace `php wp-check.phar` with
`php bin/wp-check`. Reports are written to the output directory; the default
is `./reports/wp-checker`.

## What is different in each file?

- **Full technical report:** all technical sections available for that run,
  fix boxes, code snippets, and file links.
- **Findings-only report:** the same technical report and findings, but fix
  boxes are hidden. The run writes both the historical `quality-report.html`
  filename and the clearer `quality-report-findings.html` copy; send the
  latter.
- **Client report:** the same findings in a shorter external layout. It keeps
  Summary, How to read, Fix now, Quality Score, Action Plan, the finding
  list, and OWASP when the scan produced any. It omits Risk Overview,
  baseline Delta, Top Rules, the checker-by-checker table, and Appendix. File
  paths are relative, there are no editor deep links, and the header shows
  the brand/project instead of tool metadata.
- `--no-remediation` also hides fix guidance in console and Markdown reports.

## Fix-guidance default

Fix guidance is on by default. From a source checkout, findings-only can be
made the default for every run with:

```php
'remediation' => false,
```

The default is `true`. A command-line flag overrides the config for that run
only.

For an external handoff, continue to [Client reports](client-reports.md).
