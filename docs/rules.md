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
arrives in v0.2). `$wpdb->prepare()` without placeholders is useless but not
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
