<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\Quality\LlmQualityChecker;
use Kairos\AiEditorialHelper\Service\Quality\RuleBasedQualityChecker;

/**
 * Combines fast rule-based checks (sentence length, heading hierarchy) with
 * subjective LLM-based checks (tone, clarity) into a single flag list.
 *
 * Rule-based checks always run. LLM checks are opt-in (caller passes
 * $includeLlm = true) so they don't block page save. Returns flags in
 * priority order: errors first, then warnings, then info.
 *
 * Each flag has the shape:
 *   { kind: string, severity: 'info'|'warning'|'error', message: string, location?: string }
 */
class QualityChecker
{
    public function __construct(
        private readonly RuleBasedQualityChecker $ruleBased,
        private readonly LlmQualityChecker $llmBased,
    ) {
    }

    /**
     * @return list<array{kind: string, severity: string, message: string, location?: string}>
     */
    public function check(string $title, string $html, bool $includeLlm = true): array
    {
        $flags = $this->ruleBased->check($html);

        if ($includeLlm) {
            try {
                $llmFlags = $this->llmBased->check($title, $html);
                $flags = array_merge($flags, $llmFlags);
            } catch (LmStudioException $e) {
                // Rule-based checks always survive an LLM outage. Surface a single
                // info-level flag so the editor knows LLM checks were skipped, not
                // silently dropped.
                $flags[] = [
                    'kind' => 'llm_unavailable',
                    'severity' => 'info',
                    'message' => 'LLM-based checks (tone, clarity) skipped: ' . $e->getMessage(),
                ];
            }
        }

        return $this->sortBySeverity($flags);
    }

    /**
     * @param list<array{kind: string, severity: string, message: string, location?: string}> $flags
     * @return list<array{kind: string, severity: string, message: string, location?: string}>
     */
    private function sortBySeverity(array $flags): array
    {
        $weight = ['error' => 0, 'warning' => 1, 'info' => 2];
        usort($flags, static function (array $a, array $b) use ($weight): int {
            $wa = $weight[$a['severity']] ?? 99;
            $wb = $weight[$b['severity']] ?? 99;
            if ($wa !== $wb) {
                return $wa <=> $wb;
            }
            return strcmp($a['kind'], $b['kind']);
        });
        return array_values($flags);
    }
}
