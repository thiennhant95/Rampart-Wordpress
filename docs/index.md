---
layout: default
title: Rampart WP Checker
description: Quality gate for WordPress plugins and themes.
---

# Rampart WP Checker

**One-command quality gate for WordPress plugins and themes.**

[English]({{ site.baseurl }}/en/) · [日本語]({{ site.baseurl }}/ja/) · [Tiếng Việt]({{ site.baseurl }}/vi/)

Static analysis with **zero WordPress runtime**. Point it at a plugin folder and
get a security report in seconds: WPCS, phpstan, phpunit, `$wpdb` prepare,
escaping, nonce, capability and more.

```bash
php bin/wp-check /path/to/my-plugin --min-severity=error --fail-on=none --format=html
```

## The Rampart family

| Project | Focus | Repository | Documentation |
|---|---|---|---|
| **Rampart** | Laravel Artisan quality gate | [thiennhant95/Rampart](https://github.com/thiennhant95/Rampart) | [Pages](https://thiennhant95.github.io/Rampart/) |
| **Rampart PHP** | Plain PHP + frameworks, PHP 7.4+ | [thiennhant95/Rampart-PHP](https://github.com/thiennhant95/Rampart-PHP) | [Pages](https://thiennhant95.github.io/Rampart-PHP/) |
| **Rampart WP** | WordPress plugins/themes | [thiennhant95/Rampart-Wordpress](https://github.com/thiennhant95/Rampart-Wordpress) | [Pages](https://thiennhant95.github.io/Rampart-Wordpress/) |

## Start here

- [English documentation]({{ site.baseurl }}/en/) — install, rules, reports, CI.
- [日本語ドキュメント]({{ site.baseurl }}/ja/)
- [Tài liệu tiếng Việt]({{ site.baseurl }}/vi/)

## Repository guides

- [Installation](installation.html) — Phar, Composer, source.
- [Rules](rules.html) — every WordPress rule with a true/false example.
- [CI recipes](ci.html) — GitHub Actions, GitLab.
- [Reports](reports.html) · [Client reports](client-reports.html)
- [FAQ](faq.html) — troubleshooting and false positives.

MIT © 2026 Rampart.
