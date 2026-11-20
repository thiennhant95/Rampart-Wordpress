<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Ops;

use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;
use Rampart\QualityChecker\Scanning\ScanContextAware;
use Rampart\QualityChecker\Scanning\ScanContextTrait;

/**
 * Sensitive WordPress deploy artifacts that must not be writable by every
 * local user. Compared by permission bits, not an exact octal string: 775 is
 * group-writable (Warning, context-dependent) while 772/767/757 leak
 * other-write too and used to slip through a hardcoded list.
 *
 * `wp-content/uploads` must be writable by the web server (that is how media
 * upload works), but 777 on it means any local account can drop a PHP file
 * into a web-served directory. That is the same class of risk as the
 * webshells ROGUE_PHP hunts, so it is reported here.
 */
final class PermsAnalyzer implements ScanContextAware
{
    use ScanContextTrait;

    private const RULE = 'PERMS_TOO_OPEN';

    /** @var list<string> sensitive paths relative to the scan root */
    private const CANDIDATES = [
        '.env',
        'wp-config.php',
        'web/wp-config.php',
        'wp-content/uploads',
        'web/wp-content/uploads',
    ];

    /**
     * @param list<string> $files
     * @return list<Issue>
     */
    public function analyze(array $files): array
    {
        $root = $this->scanRoot();
        if ($root === null) {
            return [];
        }
        // Only check when scanning the project root itself, not when the
        // runner is pointed at a dependency skeleton inside vendor/.
        $normalizedRoot = str_replace('\\', '/', $root);
        if (str_contains($normalizedRoot, '/vendor/')) {
            return [];
        }

        $issues = [];
        foreach (self::CANDIDATES as $relative) {
            $path = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!file_exists($path)) {
                continue;
            }
            $perms = fileperms($path);
            if ($perms === false) {
                continue;
            }
            $mode = $perms & 0777;
            $classification = self::classify($mode);
            if ($classification === null) {
                continue;
            }
            $octal = sprintf('%03o', $mode);
            $isDir = is_dir($path);
            $issues[] = new Issue(
                self::RULE,
                sprintf('Permissions too open: %s is %s (%s).', $path, $octal, $classification['scope']),
                $path,
                1,
                $classification['severity'],
                'custom',
                [
                    'perms' => $octal,
                    'path' => $path,
                    'target' => $isDir ? 'directory' : 'file',
                    'reason' => $classification['reason'],
                    'fix_hint' => self::fixHint($relative, $isDir),
                ],
                $classification['reason'] === 'other_write' ? Confidence::High : Confidence::Medium
            );
        }

        return $issues;
    }

    /**
     * Classify a POSIX mode by its write bits. World-write is Critical;
     * group-write alone is Warning because it depends on deploy context.
     *
     * @return array{severity: Severity, scope: string, reason: string}|null
     */
    public static function classify(int $mode): ?array
    {
        $mode &= 0777;
        if (($mode & 0002) !== 0) {
            return ['severity' => Severity::Critical, 'scope' => 'world-writable', 'reason' => 'other_write'];
        }
        if (($mode & 0020) !== 0) {
            return ['severity' => Severity::Warning, 'scope' => 'group-writable', 'reason' => 'group_write'];
        }

        return null;
    }

    private static function fixHint(string $relative, bool $isDir): string
    {
        if ($relative === '.env' || str_ends_with($relative, 'wp-config.php')) {
            // Secrets: 644 (WordPress default for wp-config) is not an error,
            // but once writable it should be tightened to owner-only.
            return 'chmod 600 ' . $relative . ' (or 640 if the web server needs group read)';
        }

        return $isDir ? 'chmod 755 ' . $relative : 'chmod 644 ' . $relative;
    }
}
