<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Rampart\QualityChecker\Analyzers\Security\AuthHardeningAnalyzer;
use Rampart\QualityChecker\Analyzers\Security\MassAssignmentAnalyzer;
use Rampart\QualityChecker\Analyzers\Security\ObfuscatedAnalyzer;
use Rampart\QualityChecker\Analyzers\Security\UnsafeDeserializationAnalyzer;
use Rampart\QualityChecker\Analyzers\Security\UnsafeEvalAnalyzer;
use Rampart\QualityChecker\Analyzers\Wordpress\AbspathAnalyzer;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

final class SecurityHardeningTest extends TestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'qc-wp-hard-' . uniqid('', true);
        @mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->tmp === '' || !is_dir($this->tmp)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmp, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $item) {
            $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }
        @rmdir($this->tmp);
    }

    private function file(string $rel, string $content): string
    {
        $path = $this->tmp . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        @mkdir(dirname($path), 0777, true);
        file_put_contents($path, $content);

        return $path;
    }

    /**
     * @param list<Issue> $issues
     * @return list<Issue>
     */
    private function onlyRule(array $issues, string $rule): array
    {
        return array_values(array_filter($issues, static fn (Issue $i): bool => $i->rule === $rule));
    }

    public function testWeakPasswordPolicyChecksBothTernaryBranches(): void
    {
        $file = $this->file(
            'app/Http/Requests/RegisterRequest.php',
            "<?php\nnamespace App\\Http\\Requests;\nclass RegisterRequest extends \\Illuminate\\Foundation\\Http\\FormRequest {\n" .
            "    public function rules(): array {\n" .
            "        return ['password' => \$this->strong ? 'required|min:12' : 'required|min:4'];\n" .
            "    }\n}\n"
        );

        $issues = $this->onlyRule((new AuthHardeningAnalyzer())->analyze([$file]), 'WEAK_PASSWORD_POLICY');

        self::assertNotEmpty($issues);
        self::assertStringContainsString('minimum length 4', $issues[0]->message);
    }

    public function testWeakPasswordPolicyResolvesConcatenatedLiteralVariable(): void
    {
        $file = $this->file(
            'app/Http/Requests/RegisterRequest.php',
            "<?php\nnamespace App\\Http\\Requests;\nclass RegisterRequest extends \\Illuminate\\Foundation\\Http\\FormRequest {\n" .
            "    public function rules(): array {\n" .
            "        \$n = 4;\n        return ['password' => 'required|min:' . \$n];\n" .
            "    }\n}\n"
        );

        $issues = $this->onlyRule((new AuthHardeningAnalyzer())->analyze([$file]), 'WEAK_PASSWORD_POLICY');

        self::assertNotEmpty($issues);
        self::assertStringContainsString('minimum length 4', $issues[0]->message);
    }

    public function testUnsafeEvalResolvesIndirectAssertVariableAndWrapper(): void
    {
        $direct = $this->file(
            'app/Runner.php',
            "<?php\nclass Runner {\n    public function run(string \$code): void {\n        \$fn = 'assert';\n        \$fn(\$code);\n    }\n}\n"
        );
        $wrapper = $this->file(
            'app/Wrapper.php',
            "<?php\nclass Wrapper {\n    public function run(string \$code): void {\n        call_user_func('assert', \$code);\n    }\n}\n"
        );

        foreach ([$direct, $wrapper] as $file) {
            $issues = $this->onlyRule((new UnsafeEvalAnalyzer())->analyze([$file]), 'UNSAFE_EVAL');
            self::assertNotEmpty($issues);
            self::assertSame(Severity::Critical, $issues[0]->severity);
        }
    }

    public function testUnsafeDeserializationNormalizesCaseAndIndirection(): void
    {
        $upper = $this->file('app/Upper.php', "<?php\n\$a = UNSERIALIZE(\$_GET['x']);\n");
        $indirect = $this->file(
            'app/Indirect.php',
            "<?php\nclass Indirect {\n    public function run(): void {\n        \$fn = 'unserialize';\n        \$fn(\$_GET['x']);\n    }\n}\n"
        );
        $wrapper = $this->file(
            'app/Wrapper.php',
            "<?php\n\$a = call_user_func('unserialize', \$_GET['x']);\n"
        );

        foreach ([$upper, $indirect, $wrapper] as $file) {
            $issues = $this->onlyRule((new UnsafeDeserializationAnalyzer())->analyze([$file]), 'UNSAFE_UNSERIALIZE');
            self::assertNotEmpty($issues);
            self::assertSame(Severity::Critical, $issues[0]->severity);
        }
    }

    public function testObfuscatedReportsPayloadLineAndDecodeFamily(): void
    {
        $line = $this->file('line.php', "<?php\n\n// one\n// two\n// three\neval(base64_decode(\$payload));\n");
        $issues = (new ObfuscatedAnalyzer())->analyze([$line]);
        self::assertCount(1, $issues);
        self::assertSame(6, $issues[0]->line);

        foreach (
            [
                $this->file('base85.php', "<?php\neval(base85_decode(\$payload));\n"),
                $this->file('rot.php', "<?php\neval(str_rot14(\$payload));\n"),
                $this->file('bracket.php', "<?php\neval[base64_decode(\$payload)];\n"),
            ] as $file
        ) {
            self::assertCount(1, (new ObfuscatedAnalyzer())->analyze([$file]));
        }
    }

    public function testWpRulesSkipTestFixtures(): void
    {
        $file = $this->file(
            'tests/e2e/test-plugins/demo.php',
            "<?php\n/* Plugin Name: Demo */\necho 'demo';\n"
        );

        self::assertCount(0, (new AbspathAnalyzer())->analyze([$file]));
    }

    public function testMassAssignmentKeepsValidSinkNextToSyntaxError(): void
    {
        $model = $this->file(
            'app/Models/Order.php',
            "<?php\nnamespace App\\Models;\nclass Order extends \\Illuminate\\Database\\Eloquent\\Model {\n    protected \$guarded = [];\n}\n"
        );
        $controller = $this->file(
            'app/Http/Controllers/OrderController.php',
            "<?php\nnamespace App\\Http\\Controllers;\nuse App\\Models\\Order;\nuse Illuminate\\Http\\Request;\n" .
            "class OrderController {\n    public function store(Request \$request) {\n" .
            "        Order::create(\$request->all());\n        undeclared_fn(1,,2);\n    }\n}\n"
        );

        $issues = $this->onlyRule((new MassAssignmentAnalyzer())->analyze([$controller, $model]), 'MASS_ASSIGNMENT');

        self::assertNotEmpty($issues);
        self::assertSame(Severity::Error, $issues[0]->severity);
    }
}
