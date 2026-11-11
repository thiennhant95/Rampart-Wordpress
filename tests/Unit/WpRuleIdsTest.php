<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Result\RuleIds;

/**
 * The WordPress rule registry: every WP_* id the analyzers can emit
 * must be registered (reporters and remediation agree on this class).
 */
final class WpRuleIdsTest extends TestCase
{
    public function testWordpressRulesAreRegistered(): void
    {
        $all = RuleIds::all();

        foreach (['WPDB_PREPARE', 'WP_UNESCAPED_OUTPUT', 'WP_NONCE', 'WP_CAPABILITY', 'WP_CONFIG_SECRET', 'WP_ABSPATH', 'WP_TABLE_PREFIX', 'WP_TEXTDOMAIN'] as $rule) {
            self::assertContains($rule, $all, $rule . ' missing from RuleIds::all().');
            self::assertSame($rule, constant(RuleIds::class . '::' . $rule));
        }
    }
}
