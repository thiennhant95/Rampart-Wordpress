<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * CLI wiring for the three HTML audiences (dev, findings-only, client).
 * The reporter is unit-tested separately; this proves the standalone flags
 * write the right files.
 */
final class WpHtmlVariantsCliTest extends TestCase
{
    private string $base = '';

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-wpvariants-' . uniqid('', true);
        @mkdir($this->base . DIRECTORY_SEPARATOR . 'plugin', 0777, true);
        file_put_contents($this->base . DIRECTORY_SEPARATOR . 'plugin' . DIRECTORY_SEPARATOR . 'plugin.php', "<?php\n/* Plugin Name: Demo */\n\$ok = 1;\n");
    }

    protected function tearDown(): void
    {
        if ($this->base === '' || !is_dir($this->base)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->base);
    }

    public function testAllVariantsWritesThreeFiles(): void
    {
        $out = $this->base . DIRECTORY_SEPARATOR . 'all';
        self::assertSame(0, $this->runCli(['--all-variants', '--output=' . $out]));
        foreach (['quality-report.html', 'quality-report-findings.html', 'quality-report-client.html'] as $name) {
            self::assertFileExists($out . DIRECTORY_SEPARATOR . $name, $name . ' missing');
        }
    }

    public function testClientWritesOnlyClientReport(): void
    {
        $out = $this->base . DIRECTORY_SEPARATOR . 'client';
        self::assertSame(0, $this->runCli(['--client', '--output=' . $out]));
        self::assertFileExists($out . DIRECTORY_SEPARATOR . 'quality-report-client.html');
        self::assertFileDoesNotExist($out . DIRECTORY_SEPARATOR . 'quality-report.html');
    }

    public function testNoRemediationWritesFindingsAlias(): void
    {
        $out = $this->base . DIRECTORY_SEPARATOR . 'nofix';
        self::assertSame(0, $this->runCli(['--no-remediation', '--output=' . $out]));
        self::assertFileExists($out . DIRECTORY_SEPARATOR . 'quality-report.html');
        self::assertFileExists($out . DIRECTORY_SEPARATOR . 'quality-report-findings.html');
    }

    /**
     * @param list<string> $flags
     */
    private function runCli(array $flags): int
    {
        $bin = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'wp-check';
        $cmd = [
            escapeshellarg(PHP_BINARY),
            escapeshellarg($bin),
            escapeshellarg($this->base . DIRECTORY_SEPARATOR . 'plugin'),
            '--format=html',
            '--fail-on=none',
            '--no-cache',
        ];
        foreach ($flags as $flag) {
            $cmd[] = escapeshellarg($flag);
        }
        $out = [];
        $code = 1;
        @exec(implode(' ', $cmd) . ' 2>&1', $out, $code);

        return $code;
    }
}
