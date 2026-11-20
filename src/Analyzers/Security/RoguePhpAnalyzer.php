<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Security;

use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;
use Rampart\QualityChecker\Scanning\ScanContextAware;
use Rampart\QualityChecker\Scanning\ScanContextTrait;

/**
 * PHP under a WordPress upload directory is never legitimate: uploads may
 * contain images/media and the occasional hardening `index.php`, nothing that
 * the PHP interpreter should reach. A benign stray file is still a
 * convention/security finding (Warning); content matching the obfuscation or
 * code-execution evidence is a probable webshell (Critical).
 *
 * Bedrock moves `wp-content` under `web/`, so the common layouts are covered.
 */
final class RoguePhpAnalyzer implements ScanContextAware
{
    use ScanContextTrait;

    private const RULE = 'ROGUE_PHP';

    /** @var list<string> upload roots relative to the scan root */
    private const UPLOAD_DIRS = [
        'wp-content/uploads',
        'web/wp-content/uploads',
        'public/wp-content/uploads',
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

        $issues = [];
        foreach (self::UPLOAD_DIRS as $relative) {
            $dir = $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                    continue;
                }
                if (strtolower($file->getExtension()) !== 'php') {
                    continue;
                }
                $name = $file->getFilename();
                if ($name === 'index.php') {
                    continue;
                }
                $path = $file->getPathname();
                $payload = PhpPayloadPatterns::dangerous($this->sharedSource($path));
                $metadata = [
                    'file' => $name,
                    'reason' => 'unexpected_php_in_uploads',
                    'classification' => $payload !== null ? 'dangerous' : 'benign',
                ];
                if ($payload !== null) {
                    $metadata['matched_pattern'] = $payload['label'];
                }
                $issues[] = new Issue(
                    self::RULE,
                    sprintf('Rogue PHP file in %s: %s — uploads must not contain executable PHP.', $relative, $path),
                    $path,
                    1,
                    $payload !== null ? Severity::Critical : Severity::Warning,
                    'custom',
                    $metadata,
                    $payload !== null ? Confidence::High : Confidence::Medium
                );
            }
        }

        return $issues;
    }
}
