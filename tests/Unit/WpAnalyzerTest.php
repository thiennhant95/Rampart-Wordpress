<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Analyzers\Wordpress\AbspathAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\CapabilityAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\ConfigSecretAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\NonceAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\TablePrefixAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\TextdomainAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\UnescapedOutputAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\WpdbPrepareAnalyzer;

/**
 * WordPress analyzers, v0.1 set. Each rule pins 5 true positives and
 * 5 true negatives as temp files (SPEC.md §5/§7: no rule ships without them).
 */
final class WpAnalyzerTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-wp-' . uniqid('', true);
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($it as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    private function file(string $rel, string $content): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);

        return $path;
    }

    /** @return list<string> */
    private function rules(object $analyzer, string $file): array
    {
        return array_map(static fn ($i) => $i->rule, $analyzer->analyze([$file]));
    }

    // --- WPDB_PREPARE: 5 TP ---

    public function testWpdbFlagsInterpolatedGet(): void
    {
        $f = $this->file('a.php', "<?php\n\$id = \$_GET['id'];\n\$rows = \$wpdb->query(\"SELECT * FROM t WHERE id = \$id\");\n");
        self::assertSame(['WPDB_PREPARE'], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbFlagsConcatPost(): void
    {
        $f = $this->file('a.php', "<?php\n\$rows = \$wpdb->get_results(\"SELECT * FROM t WHERE x = \" . \$_POST['x']);\n");
        self::assertSame(['WPDB_PREPARE'], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbFlagsRequestVar(): void
    {
        $f = $this->file('a.php', "<?php\n\$q = \$_REQUEST['q'];\n\$v = \$wpdb->get_var(\$q);\n");
        self::assertSame(['WPDB_PREPARE'], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbFlagsTableVar(): void
    {
        $f = $this->file('a.php', "<?php\n\$table = \$_GET['t'];\n\$row = \$wpdb->get_row(\"SELECT * FROM \$table\");\n");
        self::assertSame(['WPDB_PREPARE'], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbFlagsIndirectVar(): void
    {
        $f = $this->file('a.php', "<?php\n\$sql = \$_GET['s'];\n\$col = \$wpdb->get_col(\$sql);\n");
        self::assertSame(['WPDB_PREPARE'], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    // --- WPDB_PREPARE: 5 TN ---

    public function testWpdbSilentOnPrepare(): void
    {
        $f = $this->file('a.php', "<?php\n\$rows = \$wpdb->query(\$wpdb->prepare(\"SELECT * FROM t WHERE id = %d\", \$id));\n");
        self::assertSame([], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbSilentOnLiteral(): void
    {
        $f = $this->file('a.php', "<?php\n\$rows = \$wpdb->query(\"SELECT * FROM t\");\n");
        self::assertSame([], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbSilentOnBoundApi(): void
    {
        $f = $this->file('a.php', "<?php\n\$wpdb->insert('t', ['a' => \$_POST['x']]);\n");
        self::assertSame([], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbSilentOnOtherReceiver(): void
    {
        $f = $this->file('a.php', "<?php\n\$rows = \$other->query(\$tainted);\n");
        self::assertSame([], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbSilentOnCleanVar(): void
    {
        $f = $this->file('a.php', "<?php\n\$q = \"SELECT * FROM t\";\n\$rows = \$wpdb->query(\$q);\n");
        self::assertSame([], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbSilentOnTableProperty(): void
    {
        // Dogfood FP (Akismet): {$wpdb->comments} is a table name, not input.
        $f = $this->file('a.php', "<?php\n\$rows = \$wpdb->query(\"OPTIMIZE TABLE {\$wpdb->comments}\");\n");
        self::assertSame([], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    public function testWpdbFlagsTaintedWithPropertyMix(): void
    {
        $f = $this->file('a.php', "<?php\n\$id = \$_GET['id'];\n\$rows = \$wpdb->query(\"SELECT * FROM {\$wpdb->posts} WHERE id = \$id\");\n");
        self::assertSame(['WPDB_PREPARE'], $this->rules(new WpdbPrepareAnalyzer(), $f));
    }

    // --- WP_UNESCAPED_OUTPUT: 5 TP ---

    public function testEchoFlagsBareVar(): void
    {
        $f = $this->file('a.php', "<?php\necho \$name;\n");
        self::assertSame(['WP_UNESCAPED_OUTPUT'], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoFlagsInterpolation(): void
    {
        $f = $this->file('a.php', "<?php\necho \"hi \$name\";\n");
        self::assertSame(['WP_UNESCAPED_OUTPUT'], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testShortEchoFlags(): void
    {
        $f = $this->file('a.php', "<?= \$x ?>\n");
        self::assertSame(['WP_UNESCAPED_OUTPUT'], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testPrintFlags(): void
    {
        $f = $this->file('a.php', "<?php\nprint \$x;\n");
        self::assertSame(['WP_UNESCAPED_OUTPUT'], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoFlagsConcat(): void
    {
        $f = $this->file('a.php', "<?php\necho \$a . \$b;\n");
        self::assertSame(['WP_UNESCAPED_OUTPUT'], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    // --- WP_UNESCAPED_OUTPUT: 5 TN ---

    public function testEchoSilentOnLiteral(): void
    {
        $f = $this->file('a.php', "<?php\necho 'hi';\n");
        self::assertSame([], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoSilentOnEscHtml(): void
    {
        $f = $this->file('a.php', "<?php\necho esc_html(\$x);\n");
        self::assertSame([], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoSilentOnEscAttrWithDomain(): void
    {
        $f = $this->file('a.php', "<?php\necho esc_attr__(\$x, 'dom');\n");
        self::assertSame([], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoSilentOnCast(): void
    {
        $f = $this->file('a.php', "<?php\necho (int) \$x;\n");
        self::assertSame([], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoSilentOnCleanConcat(): void
    {
        $f = $this->file('a.php', "<?php\necho 'a' . esc_html(\$b);\n");
        self::assertSame([], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoSilentOnLiteralTernary(): void
    {
        // Dogfood FP (Akismet notice.php): ternary of two literals. The
        // condition itself is never printed, so unknown $c is fine.
        // Coalesce is clean only when both sides are ($a is a literal temp).
        $f = $this->file('a.php', "<?php\necho \$c ? 'a' : 'b';\n\$a = 'v';\n\$x = \$a ?? 'd';\necho \$x;\n");
        self::assertSame([], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    public function testEchoFlagsUnknownCoalesce(): void
    {
        $f = $this->file('a.php', "<?php\n\$x = \$ok ?? 'd';\necho \$x;\n");
        self::assertSame(['WP_UNESCAPED_OUTPUT'], $this->rules(new UnescapedOutputAnalyzer(), $f));
    }

    // --- WP_NONCE: 5 TP ---

    public function testNonceFlagsAdminPost(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('admin_post_save', 'my_save');\nfunction my_save() {\n    update_option('k', \$_POST['v']);\n}\n");
        self::assertSame(['WP_NONCE'], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceFlagsWpAjax(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_go', 'my_go');\nfunction my_go() {\n    delete_option('k');\n}\n");
        self::assertSame(['WP_NONCE'], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceFlagsStaticCallback(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('admin_post_save', 'Cls::save');\nclass Cls {\n    public static function save() {\n        update_option('k', 1);\n    }\n}\n");
        self::assertSame(['WP_NONCE'], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceFlagsSecondRegistration(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('admin_post_a', 'cb_a');\nadd_action('admin_post_b', 'cb_b');\nfunction cb_a() {\n    check_admin_referer('ok');\n}\nfunction cb_b() {\n    update_option('k', 1);\n}\n");
        self::assertSame(['WP_NONCE'], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceFlagsNoprivSkippedButAdminPostFlagged(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_nopriv_open', 'cb_open');\nadd_action('admin_post_closed', 'cb_closed');\nfunction cb_open() {\n    echo 'hi';\n}\nfunction cb_closed() {\n    update_option('k', 1);\n}\n");
        self::assertSame(['WP_NONCE'], $this->rules(new NonceAnalyzer(), $f));
    }

    // --- WP_NONCE: 5 TN ---

    public function testNonceSilentOnVerify(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('admin_post_save', 'my_save');\nfunction my_save() {\n    check_admin_referer('act');\n    update_option('k', \$_POST['v']);\n}\n");
        self::assertSame([], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceSilentOnAjaxReferer(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_go', 'my_go');\nfunction my_go() {\n    check_ajax_referer('act');\n}\n");
        self::assertSame([], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceSilentOnNopriv(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_nopriv_open', 'cb_open');\nfunction cb_open() {\n    echo 'hi';\n}\n");
        self::assertSame([], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceSilentOnInitHook(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('init', 'cb_init');\nfunction cb_init() {\n    do_something();\n}\n");
        self::assertSame([], $this->rules(new NonceAnalyzer(), $f));
    }

    public function testNonceSilentOnClosure(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('admin_post_save', function () {\n    update_option('k', 1);\n});\n");
        self::assertSame([], $this->rules(new NonceAnalyzer(), $f));
    }

    // --- WP_CAPABILITY: 5 TP ---

    public function testCapFlagsWpAjax(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_drop', 'cb_drop');\nfunction cb_drop() {\n    wp_delete_post(1);\n}\n");
        self::assertSame(['WP_CAPABILITY'], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapFlagsAdminPost(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('admin_post_wipe', 'cb_wipe');\nfunction cb_wipe() {\n    delete_option('k');\n}\n");
        self::assertSame(['WP_CAPABILITY'], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapFlagsMenuPageWithoutCap(): void
    {
        $f = $this->file('a.php', "<?php\nadd_menu_page('T', 'M', '', 'slug', 'cb_page');\nfunction cb_page() {\n    echo 'hi';\n}\n");
        self::assertSame(['WP_CAPABILITY'], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapFlagsSubmenuPageWithoutCap(): void
    {
        $f = $this->file('a.php', "<?php\nadd_submenu_page('p', 'T', 'M', '', 'slug', 'cb_sub');\nfunction cb_sub() {\n    echo 'hi';\n}\n");
        self::assertSame(['WP_CAPABILITY'], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapFlagsStaticCallback(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_nuke', 'Cls::nuke');\nclass Cls {\n    public static function nuke() {\n        wp_delete_post(1);\n    }\n}\n");
        self::assertSame(['WP_CAPABILITY'], $this->rules(new CapabilityAnalyzer(), $f));
    }

    // --- WP_CAPABILITY: 5 TN ---

    public function testCapSilentOnCurrentUserCan(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_drop', 'cb_drop');\nfunction cb_drop() {\n    if (!current_user_can('manage_options')) {\n        return;\n    }\n    wp_delete_post(1);\n}\n");
        self::assertSame([], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapSilentOnNopriv(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_nopriv_list', 'cb_list');\nfunction cb_list() {\n    echo 'hi';\n}\n");
        self::assertSame([], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapSilentOnLiteralCap(): void
    {
        $f = $this->file('a.php', "<?php\nadd_menu_page('T', 'M', 'manage_options', 'slug', 'cb_page');\nfunction cb_page() {\n    echo 'hi';\n}\n");
        self::assertSame([], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapSilentOnOptionsPageCap(): void
    {
        $f = $this->file('a.php', "<?php\nadd_options_page('T', 'M', 'edit_posts', 'slug', 'cb_opt');\nfunction cb_opt() {\n    echo 'hi';\n}\n");
        self::assertSame([], $this->rules(new CapabilityAnalyzer(), $f));
    }

    public function testCapSilentOnClosure(): void
    {
        $f = $this->file('a.php', "<?php\nadd_action('wp_ajax_go', function () {\n    wp_delete_post(1);\n});\n");
        self::assertSame([], $this->rules(new CapabilityAnalyzer(), $f));
    }

    // --- WP_CONFIG_SECRET: 5 TP ---

    public function testConfigFlagsEmptyKey(): void
    {
        $f = $this->file('wp-config.php', "<?php\ndefine('AUTH_KEY', '');\n");
        self::assertSame(['WP_CONFIG_SECRET'], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigFlagsPlaceholderPassword(): void
    {
        $f = $this->file('wp-config.php', "<?php\ndefine('DB_PASSWORD', 'changeme123');\n");
        self::assertSame(['WP_CONFIG_SECRET'], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigFlagsShortSalt(): void
    {
        $f = $this->file('wp-config.php', "<?php\ndefine('NONCE_SALT', 'abc');\n");
        self::assertSame(['WP_CONFIG_SECRET'], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigFlagsCommonPassword(): void
    {
        $f = $this->file('wp-config.php', "<?php\ndefine('AUTH_KEY', 'password123456789012345678901234567890');\n");
        self::assertSame(['WP_CONFIG_SECRET'], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigFlagsRoot(): void
    {
        $f = $this->file('wp-config.php', "<?php\ndefine('DB_PASSWORD', 'root');\n");
        self::assertSame(['WP_CONFIG_SECRET'], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    // --- WP_CONFIG_SECRET: 5 TN ---

    public function testConfigSilentOnRandomKey(): void
    {
        // No marker substrings (no 123456/password/…): a real random key.
        $f = $this->file('wp-config.php', "<?php\ndefine('AUTH_KEY', 'tRx67#kL0pQwErTyUiOpAsDfGhJkLzXcVbNmQaZ!@#');\n");
        self::assertSame([], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigSilentOnSampleFile(): void
    {
        $f = $this->file('wp-config-sample.php', "<?php\ndefine('AUTH_KEY', '');\ndefine('DB_PASSWORD', 'password_here');\n");
        self::assertSame([], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigSilentOnNonWpConfig(): void
    {
        $f = $this->file('settings.php', "<?php\ndefine('AUTH_KEY', '');\n");
        self::assertSame([], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigSilentOnLongPassword(): void
    {
        // Single-quoted: a double-quoted fixture would interpolate $mQv7.
        $f = $this->file('wp-config.php', '<?php' . "\n" . 'define(\'DB_PASSWORD\', \'x9#Kp2$mQv7!nRt4@wZy8&uJb3*eHn6%kL0dF\');' . "\n");
        self::assertSame([], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    public function testConfigSilentOnOtherConstant(): void
    {
        $f = $this->file('wp-config.php', "<?php\ndefine('WP_DEBUG', true);\ndefine('CUSTOM', 'x');\n");
        self::assertSame([], $this->rules(new ConfigSecretAnalyzer(), $f));
    }

    // --- WP_ABSPATH: 5 TP ---

    public function testAbspathFlagsUnguardedEntry(): void
    {
        $f = $this->file('my-plugin.php', "<?php\n/**\n * Plugin Name: Mine\n */\nadd_action('init', 'cb');\n");
        self::assertSame(['WP_ABSPATH'], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathFlagsEntryWithFunctions(): void
    {
        $f = $this->file('p.php', "<?php\n// Plugin Name: Mine\nfunction cb() {\n    echo 'hi';\n}\n");
        self::assertSame(['WP_ABSPATH'], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathFlagsOtherGuardOnly(): void
    {
        $f = $this->file('p.php', "<?php\n// Plugin Name: Mine\nif (!defined('OTHER')) {\n    exit;\n}\n");
        self::assertSame(['WP_ABSPATH'], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathFlagsEntryWithClass(): void
    {
        $f = $this->file('p.php', "<?php\n// Plugin Name: Mine\nclass P {\n    public function run() {}\n}\n");
        self::assertSame(['WP_ABSPATH'], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathHeaderCaseInsensitive(): void
    {
        $f = $this->file('p.php', "<?php\n// plugin name: mine\n\$x = 1;\n");
        self::assertSame(['WP_ABSPATH'], $this->rules(new AbspathAnalyzer(), $f));
    }

    // --- WP_ABSPATH: 5 TN ---

    public function testAbspathSilentOnGuard(): void
    {
        $f = $this->file('p.php', "<?php\n// Plugin Name: Mine\ndefined('ABSPATH') || exit;\n\$x = 1;\n");
        self::assertSame([], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathSilentWithoutHeader(): void
    {
        $f = $this->file('inc.php', "<?php\n\$x = 1;\n");
        self::assertSame([], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathSilentOnSpacedGuard(): void
    {
        $f = $this->file('p.php', "<?php\n// Plugin Name: Mine\nif (!defined( 'ABSPATH' )) {\n    exit;\n}\n");
        self::assertSame([], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathSilentOnIfExit(): void
    {
        $f = $this->file('p.php', "<?php\n// Plugin Name: Mine\nif (!defined('ABSPATH')) {\n    exit;\n}\nadd_action('init', 'cb');\n");
        self::assertSame([], $this->rules(new AbspathAnalyzer(), $f));
    }

    public function testAbspathSilentOnIncFile(): void
    {
        $f = $this->file('inc/lib.php', "<?php\ndefined('ABSPATH') || exit;\nfunction h() {}\n");
        self::assertSame([], $this->rules(new AbspathAnalyzer(), $f));
    }

    // --- WP_TABLE_PREFIX: 5 TP ---

    public function testPrefixFlagsSelect(): void
    {
        $f = $this->file('a.php', "<?php\n\$q = \"SELECT * FROM wp_posts\";\n");
        self::assertSame(['WP_TABLE_PREFIX'], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixFlagsUpdate(): void
    {
        $f = $this->file('a.php', "<?php\n\$wpdb->query(\"UPDATE wp_options SET v = 1\");\n");
        self::assertSame(['WP_TABLE_PREFIX'], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixFlagsDelete(): void
    {
        $f = $this->file('a.php', "<?php\n\$sql = 'DELETE FROM wp_x WHERE id = 1';\n");
        self::assertSame(['WP_TABLE_PREFIX'], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixFlagsJoin(): void
    {
        $f = $this->file('a.php', "<?php\n\$sql = \"SELECT a FROM t JOIN wp_y ON t.id = wp_y.id\";\n");
        self::assertSame(['WP_TABLE_PREFIX'], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixFlagsCreate(): void
    {
        $f = $this->file('a.php', "<?php\n\$sql = 'CREATE TABLE wp_z (id INT)';\n");
        self::assertSame(['WP_TABLE_PREFIX'], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    // --- WP_TABLE_PREFIX: 5 TN ---

    public function testPrefixSilentOnPrefixProperty(): void
    {
        $f = $this->file('a.php', "<?php\n\$q = \"SELECT * FROM \" . \$wpdb->prefix . \"posts\";\n");
        self::assertSame([], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixSilentOnProse(): void
    {
        $f = $this->file('a.php', "<?php\n\$msg = 'see the wp_admin area';\n");
        self::assertSame([], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixSilentOnOptionName(): void
    {
        $f = $this->file('a.php', "<?php\n\$v = get_option('wp_version');\n");
        self::assertSame([], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixSilentOnNoSql(): void
    {
        $f = $this->file('a.php', "<?php\n\$x = 1;\n");
        self::assertSame([], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    public function testPrefixSilentOnPropertyTable(): void
    {
        $f = $this->file('a.php', "<?php\n\$q = 'FROM ' . \$wpdb->posts;\n");
        self::assertSame([], $this->rules(new TablePrefixAnalyzer(), $f));
    }

    // --- WP_TEXTDOMAIN: 5 TP ---

    public function testDomainFlagsMissing(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = __('Save');\n");
        self::assertSame(['WP_TEXTDOMAIN'], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainFlagsMissingE(): void
    {
        $f = $this->file('a.php', "<?php\n_e('Hi');\n");
        self::assertSame(['WP_TEXTDOMAIN'], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainFlagsVariable(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = __(\$str, \$domain);\n");
        self::assertSame(['WP_TEXTDOMAIN'], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainFlagsMissingX(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = _x('a', 'b');\n");
        self::assertSame(['WP_TEXTDOMAIN'], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainFlagsMissingN(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = _n('one', 'many');\n");
        self::assertSame(['WP_TEXTDOMAIN'], $this->rules(new TextdomainAnalyzer(), $f));
    }

    // --- WP_TEXTDOMAIN: 5 TN ---

    public function testDomainSilentOnCorrect(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = __('Save', 'dom');\n");
        self::assertSame([], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainSilentOnEscHtml(): void
    {
        $f = $this->file('a.php', "<?php\necho esc_html__('S', 'd');\n");
        self::assertSame([], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainSilentOnX(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = _x('a', 'b', 'd');\n");
        self::assertSame([], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainSilentOnN(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = _n('o', 'm', \$n, 'd');\n");
        self::assertSame([], $this->rules(new TextdomainAnalyzer(), $f));
    }

    public function testDomainSilentOnOtherCall(): void
    {
        $f = $this->file('a.php', "<?php\n\$s = sprintf('%s', \$x);\n");
        self::assertSame([], $this->rules(new TextdomainAnalyzer(), $f));
    }
}
