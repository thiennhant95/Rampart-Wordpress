<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Reporters\ConsoleReporter;
use Rampart\QualityChecker\Reporters\HtmlReporter;
use Rampart\QualityChecker\Reporters\MarkdownReporter;
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

    /** @param array<string, mixed> $config */
    private function renderHtml(array $config = []): string
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-wprep-' . uniqid('', true);
        @mkdir($this->tmp, 0777, true);
        $defaults = ['html' => ['repo_url' => null, 'branch' => 'main', 'code_context' => 0]];
        $ctx = new CheckContext($this->tmp, ['.'], array_replace_recursive($defaults, $config), $this->tmp, packageVersion: '0.1.0');
        (new HtmlReporter())->render($this->results(), $ctx);

        return (string) file_get_contents($this->tmp . DIRECTORY_SEPARATOR . 'quality-report.html');
    }

    public function testWordpressBranding(): void
    {
        $html = $this->renderHtml();

        self::assertStringContainsString('WordPress Quality Report', $html);
        self::assertStringNotContainsString('Laravel Quality Report', $html);
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
