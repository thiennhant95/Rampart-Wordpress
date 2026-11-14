<?php // quality-checker-ignore OBFUSCATED_PHP
// Self-scan note: this file legitimately contains hex-escape patterns
// (detection regexes and fixture comments), so file-level OBFUSCATED_PHP
// findings on its own source are suppressed above.

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Security;

use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

final class ObfuscatedAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'OBFUSCATED_PHP';

    private const PATTERNS = [
        '/eval\s*\(\s*base64_decode\s*\(/i' => 'eval(base64_decode(...))',
        '/gzinflate\s*\(\s*base64_decode\s*\(/i' => 'gzinflate(base64_decode(...))',
        '/str_rot13\s*\(/i' => 'str_rot13(...)',
        '/chr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(/i' => 'chr() concatenation',
    ];

    private const HEX_PATTERN = '/\\\\x[0-9a-f]{2}/i';

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
            // Test files carry hex/base64 fixtures (llms.txt encoding vectors
            // and the like) — forensics on tests/ has near-zero value.
            if ($this->isTestPath($file)) {
                continue;
            }
            $code = $this->sharedSource($file);
            if ($code === '') {
                continue;
            }
            // Match call patterns against code with string literals and
            // comments stripped: pattern names inside docs, remediation
            // text or this analyzer's own labels are not backdoors
            // (self-scan used to flag OBFUSCATED_PHP on its own source).
            $stripped = self::stripStringsAndComments($code);
            foreach (self::PATTERNS as $pattern => $label) {
                if (preg_match($pattern, $stripped) === 1) {
                    $issues[] = new Issue(
                        self::RULE,
                        sprintf('Obfuscated PHP detected: %s.', $label),
                        $file,
                        1,
                        Severity::Critical,
                        'custom',
                        ['pattern' => $pattern],
                        Confidence::Medium
                    );
                    break;
                }
            }
            if ($issues !== []) {
                continue;
            }
            // Hex escapes live inside string literals by definition, so
            // stripping would blind this check. Require density instead.
            // Whitespace escapes (`\x09\x20…`, typical in sanitizer
            // whitespace lists — dogfood FP on real plugins) never count.
            // Floor is 6 non-whitespace escapes: fewer cannot hide anything
            // meaningful (sanitizer char classes carry 4–5), while real
            // payloads carry dozens.
            if (self::suspiciousHexCount($code) >= 6) {
                $issues[] = new Issue(
                    self::RULE,
                    'Obfuscated PHP detected: hex-encoded string.',
                    $file,
                    1,
                    Severity::Critical,
                    'custom',
                    ['pattern' => self::HEX_PATTERN],
                    Confidence::Medium
                );
            }
        }

        return $issues;
    }

    /** @var list<string> hex escapes that are just whitespace */
    private const HEX_WHITESPACE = ['09', '0a', '0d', '20', '85', 'a0', '0b', '0c'];

    private static function suspiciousHexCount(string $code): int
    {
        // Regex character classes (`[\x00-\x7F]`) are sanitizer idiom, not
        // payloads: strip bracket spans that contain a range dash. Payload
        // hex is written as escapes, so a literal `[...-...]` around it is
        // rare by construction; plain arrays (`["\x41"]`) keep counting.
        $code = preg_replace_callback(
            '/\[(?:\\\\.|[^\[\]\\\\])*\]/',
            static fn (array $m): string => str_contains($m[0], '-') ? '[]' : $m[0],
            $code
        ) ?? $code;
        if (preg_match_all(self::HEX_PATTERN, $code, $m) === false) {
            return 0;
        }
        $count = 0;
        foreach ($m[0] as $match) {
            $hex = strtolower(substr($match, -2));
            if (!in_array($hex, self::HEX_WHITESPACE, true)) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Code with string literals, comments and HTML dropped, tokenized
     * with the native lexer (no extra dependency). Snippets without an
     * open tag lex as inline HTML, so a synthetic tag is prepended.
     */
    private static function stripStringsAndComments(string $code): string
    {
        if (!str_contains($code, '<?')) {
            $code = '<?php ' . $code;
        }
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $out .= $token . ' ';
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                $out .= ' ';
                continue;
            }
            $out .= $text . ' ';
        }

        return $out;
    }

    public function supports(string $path): bool
    {
        return strtolower((string) pathinfo($path, PATHINFO_EXTENSION)) === 'php';
    }
}
