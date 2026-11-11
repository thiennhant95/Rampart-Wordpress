# Security Policy

## Supported versions

| Version | Supported |
|---|---|
| `main` (latest) | Yes |
| Tagged releases | Best effort |

Rampart WP Checker is a **static analyzer**. It parses plugin/theme source and
never executes WordPress or plugin code.

## Reporting a vulnerability

Do not open a public issue for a vulnerability in this tool.

- Use GitHub private vulnerability reporting on
  [thiennhant95/Rampart-Wordpress](https://github.com/thiennhant95/Rampart-Wordpress/security/advisories/new).
- Include the version, a minimal reproducer, the impact, and whether the issue
  affects the scanner or a wrapped tool invocation.

## Scanning untrusted code

- The custom analyzers only read and parse files.
- Wrapped tools (`phpcs`, `phpstan`, `phpunit`) execute the target's binaries
  when present. Do not run them on code you do not trust.
- Reports may contain code snippets and paths; treat them as confidential.
