<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Security;

/**
 * Shared, token-aware detection of PHP backdoor payloads.
 *
 * Both `ObfuscatedAnalyzer` (`OBFUSCATED_PHP`) and `RoguePhpAnalyzer`
 * (`ROGUE_PHP`) must answer "does this file hide an executable payload?" with
 * the same evidence. Keeping the patterns and the WordPress-tuned hex density
 * here stops the two rules drifting and avoids a second, looser regex.
 *
 * WordPress tuning: whitespace escapes (`\x09\x0D\x20…`, typical in sanitizer
 * whitespace lists) never count, regex character classes are stripped, and the
 * floor is 6 non-whitespace escapes. Fewer cannot hide anything meaningful
 * (sanitizer char classes carry 4–5), while real payloads carry dozens.
 */
final class PhpPayloadPatterns
{
    /** @var array<string, string> regex => human label */
    private const OBFUSCATION = [
        '/eval\s*\(\s*base64_decode\s*\(/i' => 'eval(base64_decode(...))',
        '/gzinflate\s*\(\s*base64_decode\s*\(/i' => 'gzinflate(base64_decode(...))',
        '/eval\s*\(\s*(?:str_rot\d+|base\d+_decode|[a-z_][a-z0-9_]*decode)\s*\(/i' => 'eval(decode(...))',
        '/(?<![\$\w>:])eval\s*\[/i' => 'eval[...] (malformed dynamic execution)',
        '/str_rot13\s*\(/i' => 'str_rot13(...)',
        '/chr\s*\(\s*\d+\s*\)\s*\.\s*chr\s*\(/i' => 'chr() concatenation',
    ];

    private const HEX_PATTERN = '/\\\\x[0-9a-f]{2}/i';

    /** @var list<string> hex escapes that are just whitespace */
    private const HEX_WHITESPACE = ['09', '0a', '0d', '20', '85', 'a0', '0b', '0c'];

    /**
     * Code-execution sinks that must never appear in a web-served upload
     * directory. Matched against stripped source so names inside
     * docs/comments/strings stay silent.
     */
    private const DANGEROUS_CALL = '/\b(eval|assert|create_function|shell_exec|system|exec|passthru|popen|proc_open)\s*\(/i';

    /**
     * First obfuscation signal in the source, with the regex that matched and
     * the source line it starts on.
     *
     * @return array{label: string, pattern: string, line: int}|null
     */
    public static function obfuscation(string $code): ?array
    {
        $stripped = self::stripStringsAndComments($code);
        $lineSource = null;
        foreach (self::OBFUSCATION as $pattern => $label) {
            if (preg_match($pattern, $stripped) !== 1) {
                continue;
            }
            if ($lineSource === null) {
                $lineSource = self::stripStringsAndCommentsPreservingLines($code);
            }
            if (preg_match($pattern, $lineSource, $match, PREG_OFFSET_CAPTURE) === 1) {
                return ['label' => $label, 'pattern' => $pattern, 'line' => self::lineAt($lineSource, (int) $match[0][1])];
            }

            return ['label' => $label, 'pattern' => $pattern, 'line' => 1];
        }

        // Hex escapes live inside string literals by definition, so stripping
        // would blind this check. Density does the work instead.
        if (self::suspiciousHexCount($code) >= 6) {
            $line = 1;
            if (preg_match(self::HEX_PATTERN, $code, $hexMatch, PREG_OFFSET_CAPTURE) === 1) {
                $line = self::lineAt($code, (int) $hexMatch[0][1]);
            }

            return ['label' => 'hex-encoded string', 'pattern' => self::HEX_PATTERN, 'line' => $line];
        }

        return null;
    }

    /**
     * Obfuscation OR a code-execution sink. Used by the rogue-upload rule to
     * decide whether an unexpected PHP file is a probable webshell (Critical)
     * or merely a convention violation (Warning).
     *
     * @return array{label: string, pattern: string}|null
     */
    public static function dangerous(string $code): ?array
    {
        $obfuscated = self::obfuscation($code);
        if ($obfuscated !== null) {
            return $obfuscated;
        }

        if (preg_match(self::DANGEROUS_CALL, self::stripStringsAndComments($code)) === 1) {
            return ['label' => 'dangerous function call', 'pattern' => self::DANGEROUS_CALL];
        }

        return null;
    }

    /**
     * Non-whitespace hex escapes, excluding regex character classes.
     *
     * `[\x00-\x7F]` ranges are sanitizer idiom, not payloads: bracket spans
     * containing a range dash are stripped. Plain arrays (`["\x41"]`) keep
     * counting; payload hex is written as escapes, so a literal `[...-...]`
     * around it is rare by construction.
     */
    private static function suspiciousHexCount(string $code): int
    {
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
     * Same stripping as stripStringsAndComments(), but every removed byte is
     * replaced with a space (newlines preserved), so offsets and line numbers
     * still match the original source. Used only for reporting a match line.
     */
    private static function stripStringsAndCommentsPreservingLines(string $code): string
    {
        if (!str_contains($code, '<?')) {
            $code = '<?php ' . $code;
        }
        $out = '';
        foreach (token_get_all($code) as $token) {
            if (is_string($token)) {
                $out .= $token;
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_INLINE_HTML], true)) {
                $out .= preg_replace('/[^\n]/', ' ', $text) ?? '';
                continue;
            }
            $out .= $text;
        }

        return $out;
    }

    private static function lineAt(string $source, int $offset): int
    {
        return substr_count(substr($source, 0, $offset), "\n") + 1;
    }

    /**
     * Code with string literals, comments and HTML dropped, tokenized with the
     * native lexer (no extra dependency). Snippets without an open tag lex as
     * inline HTML, so a synthetic tag is prepended.
     */
    public static function stripStringsAndComments(string $code): string
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
}
