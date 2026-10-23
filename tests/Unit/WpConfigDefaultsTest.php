<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Checkers\CustomAnalyzerChecker;
use Rampart\QualityChecker\Runner\CheckContext;
use Rampart\QualityChecker\Standalone\StandaloneRunner;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Test-coverage checks are OPT-IN, never default.
 *
 * Missing-test findings are backlog noise on most codebases ("No controller
 * test found for ..." × hundreds), so the shipped config keeps every
 * test_coverage key off. This test pins both directions: default config
 * reports no MISSING_* rules, and flipping one key on brings them back.
 */
final class WpConfigDefaultsTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-wpcfg-' . uniqid('', true);
        @mkdir($this->tmp . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers', 0777, true);
        @mkdir($this->tmp . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature', 0777, true);
        file_put_contents(
            $this->tmp . DIRECTORY_SEPARATOR . 'app' . DIRECTORY_SEPARATOR . 'Http' . DIRECTORY_SEPARATOR . 'Controllers' . DIRECTORY_SEPARATOR . 'ShopController.php',
            "<?php\nnamespace App\\Http\\Controllers;\nclass ShopController {\n    public function index() {\n        return 1;\n    }\n}\n"
        );
        file_put_contents(
            $this->tmp . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'Feature' . DIRECTORY_SEPARATOR . 'OtherTest.php',
            "<?php\nclass OtherTest extends TestCase {\n    public function test_x() {\n        \$this->assertTrue(true);\n    }\n}\n"
        );
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

    /** @return array<string, mixed> */
    private function defaultConfig(): array
    {
        $config = require dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'wp-checker.php';
        self::assertIsArray($config);

        return $config;
    }

    /**
     * @param array<string, mixed> $config
     * @return list<string>
     */
    private function rulesFor(array $config): array
    {
        $ctx = new CheckContext($this->tmp, ['app', 'tests'], $config, $this->tmp . DIRECTORY_SEPARATOR . 'out');
        $result = (new CustomAnalyzerChecker())->run($ctx);

        return array_map(static fn ($i) => $i->rule, $result->issues);
    }

    public function testShippedConfigKeepsAllTestCoverageOff(): void
    {
        $coverage = $this->defaultConfig()['analyzers']['test_coverage'] ?? null;
        self::assertIsArray($coverage);

        foreach ($coverage as $key => $enabled) {
            self::assertFalse((bool) $enabled, "test_coverage.$key must default off (opt-in).");
        }
    }

    public function testDefaultScanReportsNoMissingTestRules(): void
    {
        $rules = $this->rulesFor($this->defaultConfig());

        foreach ($rules as $rule) {
            self::assertStringStartsNotWith(
                'MISSING_',
                $rule,
                'Default scan must not report missing-test findings.'
            );
            self::assertNotSame('TEST_WITHOUT_ASSERT', $rule);
        }
    }

    public function testOptInBringsMissingTestRulesBack(): void
    {
        $config = $this->defaultConfig();
        $config['analyzers']['test_coverage']['unified'] = true;
        $rules = $this->rulesFor($config);

        $missing = array_values(array_filter(
            $rules,
            static fn (string $rule): bool => str_starts_with($rule, 'MISSING_')
        ));
        self::assertNotSame([], $missing, 'Opting in must report missing-test findings.');
    }

    public function testCoverageFlagOptsInForOneRun(): void
    {
        $out = $this->tmp . DIRECTORY_SEPARATOR . 'out-cov';
        @mkdir($out, 0777, true);

        $run = function (array $extra) use ($out): array {
            // Unique output per run: the result cache would otherwise serve
            // the first run's issues to the second.
            $runOut = $out . '-' . uniqid('', true);
            @mkdir($runOut, 0777, true);
            $output = new BufferedOutput();
            $code = (new StandaloneRunner(array_merge([
                $this->tmp,
                '--format=json',
                '--tier=security',
                '--fail-on=none',
                '--output=' . $runOut,
            ], $extra)))->run($output, dirname(__DIR__, 2));
            self::assertSame(0, $code);
            $payload = json_decode((string) file_get_contents($runOut . DIRECTORY_SEPARATOR . 'quality-report.json'), true);
            $rules = [];
            foreach ($payload['checkers'] as $checker) {
                foreach ($checker['issues'] ?? [] as $issue) {
                    $rules[] = $issue['rule'];
                }
            }

            return $rules;
        };

        foreach ($run([]) as $rule) {
            self::assertStringStartsNotWith('MISSING_', $rule, 'Default scan must not report missing-test findings.');
        }
        $missing = array_values(array_filter(
            $run(['--coverage']),
            static fn (string $rule): bool => str_starts_with($rule, 'MISSING_')
        ));
        self::assertNotSame([], $missing, '--coverage did not bring back missing-test findings.');
    }
}
