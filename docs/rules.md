# WordPress Rules Reference

Every rule with a true/false example. Copy a “will flag” block into a scratch
plugin to see the tool fire; copy a “silent” block to see it stay quiet.

Conventions: findings point at `file:line`. Severity in brackets.

## WPDB_PREPARE [error]

```php
// FLAGS — interpolated $_GET straight into the query
$id = $_GET['id'];
$rows = $wpdb->query("SELECT * FROM t WHERE id = $id");

// SILENT — prepared
$rows = $wpdb->query($wpdb->prepare("SELECT * FROM t WHERE id = %d", $id));

// SILENT — bound API (no SQL string at all)
$wpdb->insert('t', ['a' => $_POST['x']]);

// SILENT — table-name properties are internals, not input
$rows = $wpdb->query("OPTIMIZE TABLE {$wpdb->posts}");
```

Unknown function parameters are treated as clean (cross-function taint
arrives with a future taint engine). `$wpdb->prepare()` without placeholders is useless but not
injectable — not flagged here.

## WP_UNESCAPED_OUTPUT [warning]

```php
// FLAGS
echo $name;
echo "hi $name";
<?= $x ?>

// SILENT — the escaping family
echo esc_html($x);
echo esc_attr__($x, 'dom');
echo wp_kses_post($html);

// SILENT — casts and literal temps
echo (int) $x;
$x = 'static';
echo $x;
```

Mirrors WPCS `EscapeOutput` semantics: when in doubt it flags (Warning, never
fails the gate). `__()` translates but does NOT escape — use `esc_html__()`.

## WP_NONCE [warning]

```php
// FLAGS — state-changing handler, no verification
add_action('admin_post_save', 'my_save');
function my_save() {
    update_option('k', $_POST['v']);
}

// SILENT — verified
add_action('admin_post_save', 'my_save');
function my_save() {
    check_admin_referer('my-action');
    update_option('k', $_POST['v']);
}

// SILENT — public AJAX and non-stateful hooks are out of scope
add_action('wp_ajax_nopriv_list', 'cb'); // + closures (unresolvable, skipped)
```

Accepted verifiers: `wp_verify_nonce`, `check_admin_referer`,
`check_ajax_referer`, `wp_nonce_ays`.

## WP_CAPABILITY [error]

```php
// FLAGS — privileged handler, no check
add_action('wp_ajax_drop', 'cb_drop');
function cb_drop() { wp_delete_post(1); }

// SILENT — checked
function cb_drop() {
    if (!current_user_can('manage_options')) return;
    wp_delete_post(1);
}

// SILENT — literal capability is enforced by WordPress core itself
add_menu_page('T', 'M', 'manage_options', 'slug', 'cb_page');
```

`user_can`/`author_can` also count. `wp_ajax_nopriv_*` is out of scope.

## WP_CONFIG_SECRET [warning]

```php
// wp-config.php — FLAGS
define('AUTH_KEY', '');
define('DB_PASSWORD', 'changeme123');
define('NONCE_SALT', 'abc');          // too short (< 32 chars)

// SILENT — real random keys, and everything in wp-config-sample.php
define('AUTH_KEY', 'tRx67#kL0pQwErTyUiOpAsDfGhJkLzXcVbNmQaZ!@#');
```

## WP_ABSPATH [warning]

```php
// my-plugin.php — FLAGS (has Plugin Name header, no guard)
<?php
/**
 * Plugin Name: Mine
 */
add_action('init', 'cb');

// SILENT
defined('ABSPATH') || exit;
```

## WP_TABLE_PREFIX [info, opt-in]

```php
// FLAGS — breaks sites with renamed prefixes
$sql = "SELECT * FROM wp_posts";

// SILENT
$sql = "SELECT * FROM " . $wpdb->prefix . "posts";
```

Enable: `'table_prefix' => true` under `analyzers.wordpress`.

## WP_TEXTDOMAIN [info, opt-in]

```php
// FLAGS
$s = __('Save');            // no domain
$s = __($str, $domain);     // variable domain

// SILENT
$s = __('Save', 'my-plugin');
```

Enable: `'textdomain' => true` under `analyzers.wordpress`.

## Deploy & forensics (inherited)

### PERMS_TOO_OPEN [critical/warning]

Compared by write bits, not a hardcoded octal list:

- `o+w` (world-writable: 777, 767, 757, ...) -> **critical**.
- `g+w` only (775, ...) -> **warning** (depends on deploy context).
- 644/755 (and `.env` at 644, the common default) -> silent.

Checked at the scan root: `.env`, `wp-config.php`, `web/wp-config.php`,
`wp-content/uploads`, `web/wp-content/uploads`. Secrets get a `600`/`640`
fix hint; directories get `755`.

### EOL_COMPONENT [warning]

Reads `composer.json` `require.php` (and `laravel/framework`, irrelevant on a
plain WordPress install). Only fires when every mentioned line is EOL:
`>=8.1` and any supported line stay silent. Lines older than the tracked
table (PHP 7.x) are reported with a generic "older than 8.0" message.

### MISSING_COMPOSER [warning] / INVALID_CONSTRAINT [error]

`MISSING_COMPOSER` only fires when Composer evidence exists (`vendor/`,
`composer.lock`, or `bin/console`); WordPress core never reports. An
existing `composer.json` that is not valid JSON, or a `php` constraint that
is not a version string, is `INVALID_CONSTRAINT`.

### ROGUE_PHP [critical/warning]

Scans `wp-content/uploads` (plus Bedrock-style `web/wp-content/uploads` and
`public/wp-content/uploads`). Any PHP file other than the hardening
`index.php` is a finding. Content matching `OBFUSCATED_PHP` evidence or a
code-execution sink (`eval`, `system`, `shell_exec`, ...) is **critical**;
an otherwise benign stray file stays a **warning**.

```php
// FLAGS (critical) — in wp-content/uploads/2026/01/x.php
<?php eval(base64_decode('...'));

// FLAGS (warning) — misplaced but harmless
<?php echo 'hello';

// SILENT
wp-content/uploads/index.php   // hardening stub

// SILENT
wp-content/uploads/photo.jpg
```
