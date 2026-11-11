# CI Recipes

## GitHub Actions (recommended)

```yaml
name: quality
on: [push, pull_request]
jobs:
  wp-check:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.2'
      - name: Download wp-check
        run: |
          curl -sL -o wp-check.phar https://github.com/thiennhant95/Rampart-Wordpress/releases/latest/download/wp-check.phar
      - name: Scan (fail on error+)
        run: php wp-check.phar . --fail-on=error --format=sarif,console --output=reports/
      - name: Upload SARIF
        if: always()
        uses: github/codeql-action/upload-sarif@v3
        with:
          sarif_file: reports/quality-report.sarif
```

Notes:
- The repo under test is a plugin/theme directory itself — no install step.
- Start new projects with `--fail-on=error`. Legacy codebases: first run
  `--fail-on=none --baseline-generate`, commit `baseline.json`, then gate on it:
  `php wp-check.phar . --baseline-file=baseline.json` (fails only on new findings).

## GitLab CI

```yaml
wp-check:
  image: php:8.2-cli
  script:
    - curl -sL -o wp-check.phar https://github.com/thiennhant95/Rampart-Wordpress/releases/latest/download/wp-check.phar
    - php wp-check.phar . --fail-on=error --format=json --output=reports/
  artifacts:
    paths: [reports/]
    when: always
```

## Failing only on new findings (any CI)

```bash
# once, on main:
php wp-check.phar . --fail-on=none --baseline-generate   # writes baseline.json
git add baseline.json && git commit -m "chore: quality baseline"
# every run after:
php wp-check.phar . --baseline-file=baseline.json        # exit 1 only on new issues
```
