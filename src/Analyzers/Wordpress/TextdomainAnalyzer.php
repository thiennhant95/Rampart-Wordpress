<?php

declare(strict_types=1);

namespace Rampart\QualityChecker\Analyzers\Wordpress;

use PhpParser\Node;
use Rampart\QualityChecker\Analyzers\AbstractAnalyzer;
use Rampart\QualityChecker\Result\Confidence;
use Rampart\QualityChecker\Result\Issue;
use Rampart\QualityChecker\Result\Severity;

/**
 * WP_TEXTDOMAIN — translation calls with a missing or variable text domain
 * (Info/Low, opt-in). Without a literal domain the string is untranslatable
 * (`__('Save')`) or resolved at runtime (`__($s, $domain)`).
 * `esc_html__()` with a proper domain is the safe pattern — and is additionally
 * an output escaper, so it never flags here or in WP_UNESCAPED_OUTPUT.
 */
final class TextdomainAnalyzer extends AbstractAnalyzer
{
    private const RULE = 'WP_TEXTDOMAIN';

    /** @var array<string, int> function => domain argument index */
    private const FUNCS = [
        '__' => 1, '_e' => 1, '_x' => 2, '_ex' => 2, '_n' => 3, '_nx' => 4,
        'esc_html__' => 1, 'esc_attr__' => 1, 'esc_html_e' => 1, 'esc_attr_e' => 1,
    ];

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
            $calls = WpHooks::findCalls($ast, array_keys(self::FUNCS), $this->finder());
            foreach ($calls as $call) {
                $fname = strtolower($call->name instanceof Node\Name ? $call->name->toString() : '');
                if (!isset(self::FUNCS[$fname])) {
                    continue;
                }
                $arg = $call->getArgs()[self::FUNCS[$fname]] ?? null;
                if ($arg instanceof Node\Arg && $arg->value instanceof Node\Scalar\String_ && trim($arg->value->value) !== '') {
                    continue;
                }
                $issues[] = $this->makeIssue(
                    self::RULE,
                    sprintf("Translation call %s() without a literal text domain.", $fname),
                    $file,
                    $call->getStartLine(),
                    Severity::Info,
                    ['function' => $fname],
                    Confidence::Low
                );
            }
        }

        return $issues;
    }
}
