# Installation

## Requirements

- **PHP 8.1+** to *run* the tool (`php -v` to check). Ubuntu/Debian:
  `sudo apt install php-cli`; Windows: use the installer from windows.php.net
  (add PHP to PATH); macOS: `brew install php`.
- The tool **scans** any PHP version — WordPress 6.x plugins written for
  PHP 7.x are fine.

## Option A — Phar (recommended, no Composer)

1. Download `wp-check.phar` and `wp-check.phar.sha256` from GitHub Releases.
2. Verify integrity:
   - Linux/macOS: `sha256sum -c wp-check.phar.sha256`
   - Windows PowerShell:
     ```powershell
     $h = (Get-FileHash wp-check.phar -Algorithm SHA256).Hash
     $e = (Get-Content wp-check.phar.sha256).Split()[0].ToUpper()
     if ($h -ne $e) { throw 'checksum mismatch' } else { 'checksum OK' }
     ```
3. Run: `php wp-check.phar --version` → e.g. `rampart wp-check v0.1.0`.
   If you see a version, you are done.

## Option B — Composer via GitHub (verified working)

```bash
composer config repositories.wp-checker vcs https://github.com/VietVang-JSC/-rampart-wp-checker
composer require --dev rampart/wp-quality-checker:^0.3
php vendor/bin/wp-check /path/to/plugin
```

## Option C — Composer via Packagist (pending submit)

```bash
composer require --dev rampart/wp-quality-checker
```

Until the Packagist submit lands, use A, B or D.

## Option D — From source

```bash
git clone <repo> rampart-wp-checker && cd rampart-wp-checker
composer install --no-interaction
php bin/wp-check /path/to/plugin
```

## First scan checklist

1. `php wp-check.phar /path/to/plugin --min-severity=error --fail-on=none --format=html`
2. Open `reports/wp-checker/quality-report.html` (created next to where you ran).
3. `critical` first, `error` second, warnings as backlog (see README severity table).

## Updating

- Phar: download the new release, re-verify the checksum, replace the file.
- Composer: `composer update rampart/wp-quality-checker --with-dependencies`.
