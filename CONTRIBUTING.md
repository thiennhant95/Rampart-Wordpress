# Contributing to Rampart WP Checker

Thanks for helping improve the WordPress quality gate.

## Development setup

```bash
git clone https://github.com/thiennhant95/Rampart-Wordpress.git
cd Rampart-Wordpress
composer install
composer test
composer lint
composer analyse
```

The tool is static: it parses plugin/theme code and must never require a
WordPress runtime.

## Adding or changing a rule

1. Add the rule id to the registry and analyzer.
2. Add a **true-positive** and **false-positive** case to the tests.
3. Document the rule in `README.md` and `docs/rules.md`.
4. Add or update the remediation text when the report shows fix guidance.
5. Ensure the rule is safe on non-WordPress PHP (skip when the target is not a
   plugin/theme).

## Quality bar

- `composer test`, `composer lint`, `composer analyse` must pass.
- Scans must not require WordPress and must not execute plugin code.
- Findings must include file:line, severity and a fix sample.

## Commit and PR

- One logical change per commit.
- Use the pull request template; link the issue.
- Do not commit `vendor/`, reports, PHAR archives or generated baselines.

## License

By contributing you agree that your work is licensed under the MIT License.
