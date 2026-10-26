<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Remediation\RuleRemediation;
use Rampart\QualityChecker\Result\RuleIds;
use Rampart\QualityChecker\Standalone\StandaloneRunner;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Docs cannot rot: the README WP rules table, the remediation catalog and
 * the standalone help must agree with the code. A new WP rule without a
 * README row, a remediation entry or help coverage fails the build.
 */
final class WpDocsTest extends TestCase
{
    /** @var list<string> */
    private const WP_RULES = [
        'WPDB_PREPARE',
        'WP_UNESCAPED_OUTPUT',
        'WP_NONCE',
        'WP_CAPABILITY',
        'WP_CONFIG_SECRET',
        'WP_ABSPATH',
        'WP_TABLE_PREFIX',
        'WP_TEXTDOMAIN',
    ];

    public function testWordpressRulesAreRegistered(): void
    {
        $all = RuleIds::all();

        foreach (self::WP_RULES as $rule) {
            self::assertContains($rule, $all, $rule . ' missing from RuleIds::all().');
        }
    }

    public function testReadmeDocumentsEveryWordpressRule(): void
    {
        $readme = (string) file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'README.md');

        foreach (self::WP_RULES as $rule) {
            self::assertStringContainsString(
                '`' . $rule . '`',
                $readme,
                $rule . ' has no backticked row in the README WP rules table.'
            );
        }
    }

    public function testRemediationCoversEveryWordpressRule(): void
    {
        foreach (self::WP_RULES as $rule) {
            $entry = RuleRemediation::for($rule);
            self::assertIsArray($entry, $rule . ' has no remediation entry (reports render empty).');
            self::assertNotSame('', trim((string) ($entry['why'] ?? '')), $rule . ' remediation has no why.');
            self::assertNotSame('', trim((string) ($entry['fix'] ?? '')), $rule . ' remediation has no fix.');
        }
    }

    public function testStandaloneHelpListsKeyFlags(): void
    {
        $output = new BufferedOutput();
        $code = (new StandaloneRunner(['--help']))->run($output, dirname(__DIR__, 2));

        self::assertSame(0, $code);
        $help = $output->fetch();
        foreach (['--min-severity', '--ignore', '--no-remediation', '--coverage', '--format=', '--exclude-path', '--baseline-file'] as $flag) {
            self::assertStringContainsString($flag, $help, $flag . ' missing from standalone help.');
        }
    }
}
