<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Reporters\ConsoleReporter;
use Rampart\QualityChecker\Reporters\HtmlReporter;
use Rampart\QualityChecker\Reporters\MarkdownReporter;
use Rampart\QualityChecker\Reporters\QualityScore;
use Rampart\QualityChecker\Result\CheckResult;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;
use Rampart\QualityChecker\Runner\CheckContext;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Reporter structure and the two report modes (findings vs findings+fix),
 * mirroring the parent's reporter contract on WordPress fixtures.
 */
final class WpReporterModesTest extends TestCase
{
    private string $tmp = '';

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

    /** @return CheckResult[] */
    private function results(): array
    {
        return [
            new CheckResult('custom', 'failed', 0.1, [
                new Issue('WPDB_PREPARE', 'Unprepared query.', 'db.php', 3, Severity::Error, 'custom', [], Confidence::High),
                new Issue('WP_TEXTDOMAIN', 'No domain.', 'l10n.php', 5, Severity::Info, 'custom', [], Confidence::Low),
            ], null, '2 issues'),
        ];
    }

    /**
     * @param array<string, mixed> $config
     * @param list<array<string, mixed>>|null $rawIssues
     */
    private function renderHtml(array $config = [], ?array $rawIssues = null): string
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-wprep-' . uniqid('', true);
        @mkdir($this->tmp, 0777, true);
        $defaults = ['html' => ['repo_url' => null, 'branch' => 'main', 'code_context' => 0]];
        $ctx = new CheckContext($this->tmp, ['.'], array_replace_recursive($defaults, $config), $this->tmp, packageVersion: '0.1.0');
        $results = $rawIssues === null
            ? $this->results()
            : [new CheckResult('custom', 'failed', 0.1, array_map(
                static fn (array $r): Issue => new Issue(
                    $r['rule'],
                    'm',
                    'f.php',
                    1,
                    Severity::from($r['severity']),
                    'custom',
                    [],
                    Confidence::from($r['confidence'])
                ),
                $rawIssues
            ), null, '')];
        (new HtmlReporter())->render($results, $ctx);

        return (string) file_get_contents($this->tmp . DIRECTORY_SEPARATOR . 'quality-report.html');
    }

    public function testWordpressBranding(): void
    {
        $html = $this->renderHtml();

        self::assertStringContainsString('WordPress Quality Report', $html);
        self::assertStringNotContainsString('Laravel Quality Report', $html);
        self::assertStringNotContainsString('Laravel Best Practices', $html);
    }

    public function testWordpressSecurityRulesCountAsSecurity(): void
    {
        // WPDB_PREPARE error/medium must be P1 (security), not P2/P3.
        self::assertSame('P1', QualityScore::priorityFor('WPDB_PREPARE', 'custom', 'error', 'medium'));
        self::assertSame('P1', QualityScore::priorityFor('WP_CAPABILITY', 'custom', 'warning', 'medium'));

        $results = [
            new CheckResult('custom', 'failed', 0.1, [
                new Issue('WPDB_PREPARE', 'm', 'db.php', 1, Severity::Error, 'custom', [], Confidence::Medium),
                new Issue('WP_TABLE_PREFIX', 'm', 'db.php', 2, Severity::Info, 'custom', [], Confidence::Low),
                new Issue('SOME_FUTURE_RULE', 'm', 'x.php', 3, Severity::Warning, 'custom', [], Confidence::Medium),
            ], null, ''),
        ];
        $cats = [];
        foreach (QualityScore::riskOverview($results) as $row) {
            $cats[$row['category']] = $row['findings'];
        }
        self::assertSame(1, $cats['Security'] ?? 0);
        self::assertSame(1, $cats['Maintainability'] ?? 0);
        self::assertSame(1, $cats['General'] ?? 0);
        self::assertArrayNotHasKey('Laravel Best Practices', $cats);
    }

    public function testRestructuredSectionsExist(): void
    {
        $html = $this->renderHtml();

        foreach (['id="how-to-read"', 'id="fix-now"', 'id="appendix"', 'Glossary'] as $needle) {
            self::assertStringContainsString($needle, $html);
        }
    }

    public function testRemediationShownByDefault(): void
    {
        self::assertStringContainsString('class="fixbox"', $this->renderHtml());
    }

    public function testSummaryStaysSlim(): void
    {
        $html = $this->renderHtml();
        $start = strpos($html, 'id="summary"');
        self::assertIsInt($start);
        $section = substr($html, $start, 2500);

        foreach (['Total Issues', 'Severity distribution'] as $kept) {
            self::assertStringContainsString($kept, $section);
        }
        // Stat-tile labels (not the priority legend further down).
        foreach (['>Checkers</div>', '>P0</div>', '<h3>Top rules</h3>'] as $dropped) {
            self::assertStringNotContainsString($dropped, $section);
        }
    }

    public function testRiskTableShowsPrioritySplit(): void
    {
        $html = $this->renderHtml();
        $start = strpos($html, 'id="risk-overview"');
        self::assertIsInt($start);
        $section = substr($html, $start, 2500);

        foreach (['P0', 'P1', 'P2', 'P3', 'Action'] as $needle) {
            self::assertStringContainsString($needle, $section);
        }
    }

    public function testOwaspRulesCarrySeverityBreakdown(): void
    {
        $html = $this->renderHtml([], [
            ['rule' => 'OWASP_SSRF', 'severity' => 'error', 'confidence' => 'high'],
            ['rule' => 'OWASP_BLADE_XSS', 'severity' => 'warning', 'confidence' => 'medium'],
        ]);

        self::assertStringContainsString('data-err="1"', $html);
        self::assertStringContainsString('data-warn="1"', $html);
        self::assertStringContainsString('owasp-count', $html);
    }

    public function testFixNowCollapsesByDefault(): void
    {
        $html = $this->renderHtml([], [
            ['rule' => 'OWASP_SSRF', 'severity' => 'critical', 'confidence' => 'high'],
        ]);
        $start = strpos($html, 'id="fix-now"');
        self::assertIsInt($start);
        $section = substr($html, $start, 1500);

        self::assertStringContainsString('<details>', $section);
        self::assertStringNotContainsString('<details open', $section);
    }

    public function testRemediationHiddenWhenDisabled(): void
    {
        $html = $this->renderHtml(['remediation' => false]);

        self::assertStringNotContainsString('class="fixbox"', $html);
        self::assertStringContainsString('Findings-only mode', $html);
    }

    public function testConsoleRespectsMode(): void
    {
        $out = new BufferedOutput();
        (new ConsoleReporter($out))->render(
            $this->results(),
            new CheckContext(sys_get_temp_dir(), ['.'], [], sys_get_temp_dir(), packageVersion: '0.1.0')
        );
        self::assertStringContainsString('Remediation', $out->fetch());

        $ctx = new CheckContext(sys_get_temp_dir(), ['.'], ['remediation' => false], sys_get_temp_dir(), packageVersion: '0.1.0');
        $out2 = new BufferedOutput();
        (new ConsoleReporter($out2))->render($this->results(), $ctx);
        self::assertStringNotContainsString('Remediation', $out2->fetch());
    }

    public function testMarkdownRespectsMode(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-wpmd-' . uniqid('', true);
        @mkdir($dir, 0777, true);
        try {
            (new MarkdownReporter())->render(
                $this->results(),
                new CheckContext(sys_get_temp_dir(), ['.'], [], $dir, packageVersion: '0.1.0')
            );
            self::assertStringContainsString('## Remediation', (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'quality-report.md'));

            (new MarkdownReporter())->render(
                $this->results(),
                new CheckContext(sys_get_temp_dir(), ['.'], ['remediation' => false], $dir, packageVersion: '0.1.0')
            );
            self::assertStringNotContainsString('## Remediation', (string) file_get_contents($dir . DIRECTORY_SEPARATOR . 'quality-report.md'));
        } finally {
            foreach (glob($dir . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($dir);
        }
    }
}
