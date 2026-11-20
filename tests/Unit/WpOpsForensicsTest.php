<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Analyzers\Ops\ComposerManifestAnalyzer;
use Rampart\QualityChecker\Analyzers\Ops\EolAnalyzer;
use Rampart\QualityChecker\Analyzers\Ops\PermsAnalyzer;
use Rampart\QualityChecker\Analyzers\Security\RoguePhpAnalyzer;
use Rampart\QualityChecker\Result\Severity;
use Rampart\QualityChecker\Scanning\ScanContext;
use Rampart\QualityChecker\Scanning\ScanContextAware;

/**
 * Deploy/forensics parity with the parent tool: bit-based permissions, EOL
 * below the tracked map, Composer manifest gates and content-graded rogue
 * uploads. WordPress upload roots replace the parent's public/ target.
 */
final class WpOpsForensicsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wp-ops-' . uniqid('', true);
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp !== '' && is_dir($this->tmp)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $item) {
                $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
            }
            @rmdir($this->tmp);
        }
    }

    private function file(string $rel, string $content = "<?php\n"): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);

        return $path;
    }

    private function attach(ScanContextAware $analyzer): void
    {
        $ctx = new ScanContext();
        $ctx->setBasePath($this->tmp);
        $analyzer->setScanContext($ctx);
    }

    /**
     * @param array<string, string> $require
     * @return list<\Rampart\QualityChecker\Result\Issue>
     */
    private function eol(array $require): array
    {
        file_put_contents($this->tmp . DIRECTORY_SEPARATOR . 'composer.json', json_encode(['require' => $require]));
        $analyzer = new EolAnalyzer();
        $this->attach($analyzer);

        return $analyzer->analyze([]);
    }

    /** @return list<\Rampart\QualityChecker\Result\Issue> */
    private function composer(): array
    {
        $analyzer = new ComposerManifestAnalyzer();
        $this->attach($analyzer);

        return $analyzer->analyze([]);
    }

    /** @return list<\Rampart\QualityChecker\Result\Issue> */
    private function rogue(): array
    {
        $analyzer = new RoguePhpAnalyzer();
        $this->attach($analyzer);

        return $analyzer->analyze([]);
    }

    public function testPermsClassifyByBits(): void
    {
        self::assertSame('other_write', PermsAnalyzer::classify(0777)['reason'] ?? null);
        self::assertSame('group_write', PermsAnalyzer::classify(0775)['reason'] ?? null);
        self::assertNull(PermsAnalyzer::classify(0644));
        self::assertNull(PermsAnalyzer::classify(0755));
    }

    public function testEolReportsPhpBelowTrackedMap(): void
    {
        $issues = $this->eol(['php' => '^7.4']);
        self::assertCount(1, $issues);
        self::assertStringContainsString('older than 8.0', $issues[0]->message);
        self::assertTrue($issues[0]->metadata['below_supported']);
    }

    public function testEolSilentOnOpenRange(): void
    {
        self::assertSame([], $this->eol(['php' => '^8.3']));
        self::assertSame([], $this->eol(['php' => '>=7.4']));
    }

    public function testComposerMissingFiresWithVendorEvidence(): void
    {
        @mkdir($this->tmp . DIRECTORY_SEPARATOR . 'vendor', 0777, true);
        $issues = $this->composer();
        self::assertCount(1, $issues);
        self::assertSame('MISSING_COMPOSER', $issues[0]->rule);
    }

    public function testComposerMissingSilentForBareWpCore(): void
    {
        $this->file('wp-config.php');
        self::assertSame([], $this->composer());
    }

    public function testComposerInvalidJsonIsError(): void
    {
        $this->file('composer.json', '{not json');
        $issues = $this->composer();
        self::assertCount(1, $issues);
        self::assertSame('INVALID_CONSTRAINT', $issues[0]->rule);
    }

    public function testBenignUploadPhpIsWarning(): void
    {
        $this->file('wp-content/uploads/2026/01/tool.php');
        $issues = $this->rogue();
        self::assertCount(1, $issues);
        self::assertSame(Severity::Warning, $issues[0]->severity);
        self::assertSame('benign', $issues[0]->metadata['classification']);
    }

    public function testPayloadUploadPhpIsCritical(): void
    {
        $this->file('wp-content/uploads/2026/01/x.php', "<?php\neval(base64_decode('ZWNobyAxOw=='));\n");
        $issues = $this->rogue();
        self::assertCount(1, $issues);
        self::assertSame(Severity::Critical, $issues[0]->severity);
        self::assertSame('dangerous', $issues[0]->metadata['classification']);
    }

    public function testUploadIndexAndNonPhpStaySilent(): void
    {
        $this->file('wp-content/uploads/index.php');
        $this->file('wp-content/uploads/photo.jpg', 'binary');
        self::assertSame([], $this->rogue());
    }

    public function testPublicDirIsNoLongerTheRogueTarget(): void
    {
        $this->file('public/shell.php');
        self::assertSame([], $this->rogue());
    }
}
