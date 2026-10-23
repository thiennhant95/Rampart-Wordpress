<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Analyzers\Security\ObfuscatedAnalyzer;

/**
 * Hex-density tuning from real-plugin dogfood: whitespace escape runs
 * (sanitizer lists like \x09\x0D\x20\x85\xA0) are not obfuscation.
 */
final class ObfuscatedHexTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-obf-' . uniqid('', true);
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->tmp . DIRECTORY_SEPARATOR . '*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->tmp);
    }

    private function file(string $content): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . 'a.php';
        file_put_contents($path, $content);

        return $path;
    }

    public function testWhitespaceEscapesStaySilent(): void
    {
        $f = $this->file("<?php\n\$ws = \"\\x09\\x0D\\x20\\x85\\xA0\";\n\$x = trim(\$s);\n");
        self::assertSame([], (new ObfuscatedAnalyzer())->analyze([$f]));
    }

    public function testRealHexRunStillFlags(): void
    {
        $bs = chr(92);
        $f = $this->file('<?php $a = "' . $bs . 'x65' . $bs . 'x76' . $bs . 'x61' . $bs . 'x6c";' . "\n");
        $issues = (new ObfuscatedAnalyzer())->analyze([$f]);
        self::assertCount(1, $issues);
        self::assertSame('OBFUSCATED_PHP', $issues[0]->rule);
    }
}
