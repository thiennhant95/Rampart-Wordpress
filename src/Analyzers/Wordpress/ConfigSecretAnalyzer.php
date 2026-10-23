<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WP_CONFIG_SECRET — weak secrets in wp-config files.
 *
 * Only files named `wp-config*.php`, except `*-sample.php` templates (stock
 * WordPress ships `wp-config-sample.php` with empty/placeholder values —
 * flagging the template is noise). For the 9 sensitive constants
 * (AUTH_KEY, SECURE_AUTH_KEY, LOGGED_IN_KEY, NONCE_KEY, the 4 SALTs,
 * DB_PASSWORD), flag: empty values, placeholder markers
 * ('put your unique phrase here', 'changeme', 'password', 'root', …) and
 * values shorter than 32 chars. Real 64-char random keys stay silent.
 */
final class ConfigSecretAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WP_CONFIG_SECRET';

    /** @var list<string> */
    private const CONSTANTS = [
        'AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY', 'NONCE_KEY',
        'AUTH_SALT', 'SECURE_AUTH_SALT', 'LOGGED_IN_SALT', 'NONCE_SALT',
        'DB_PASSWORD',
    ];

    /** @var list<string> */
    private const PLACEHOLDERS = [
        'put your unique phrase here', 'changeme', 'change me', 'password',
        'secret', 'example', 'test', 'testing', '123456', 'root', 'admin',
    ];

    public function supports(string $path): bool
    {
        $base = strtolower((string) pathinfo($path, PATHINFO_BASENAME));
        if (!str_starts_with($base, 'wp-config') || !str_ends_with($base, '.php')) {
            return false;
        }

        return !str_contains($base, 'sample');
    }

    /**
     * @param list<string> $files
     * @return list<Issue>
     */
    public function analyze(array $files): array
    {
        $issues = [];
        foreach ($files as $file) {
            if (!$this->supports($file)) {
                continue;
            }
            $ast = $this->sharedAst($file);
            if ($ast === null) {
                continue;
            }
            $calls = $this->finder()->find($ast, static function (Node $node): bool {
                return $node instanceof Node\Expr\FuncCall
                    && $node->name instanceof Node\Name
                    && strtolower($node->name->toString()) === 'define';
            });
            foreach ($calls as $call) {
                if (!$call instanceof Node\Expr\FuncCall) {
                    continue;
                }
                $args = $call->getArgs();
                $nameArg = $args[0] ?? null;
                $valueArg = $args[1] ?? null;
                if (
                    !$nameArg instanceof Node\Arg
                    || !$nameArg->value instanceof Node\Scalar\String_
                    || !$valueArg instanceof Node\Arg
                    || !$valueArg->value instanceof Node\Scalar\String_
                ) {
                    continue;
                }
                $name = strtoupper($nameArg->value->value);
                if (!in_array($name, self::CONSTANTS, true)) {
                    continue;
                }
                $value = $valueArg->value->value;
                $kind = $this->weakKind($value);
                if ($kind === null) {
                    continue;
                }
                $issues[] = $this->makeIssue(
                    self::RULE,
                    sprintf('%s is %s in wp-config — use a long random value.', $name, $kind),
                    $file,
                    $call->getStartLine(),
                    Severity::Warning,
                    ['constant' => $name, 'kind' => $kind],
                    Confidence::Medium
                );
            }
        }

        return $issues;
    }

    private function weakKind(string $value): ?string
    {
        if (trim($value) === '') {
            return 'empty';
        }
        $lower = strtolower($value);
        foreach (self::PLACEHOLDERS as $marker) {
            if (str_contains($lower, $marker)) {
                return 'a placeholder';
            }
        }
        if (strlen($value) < 32) {
            return 'too short';
        }

        return null;
    }
}
