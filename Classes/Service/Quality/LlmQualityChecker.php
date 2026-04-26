<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service\Quality;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\LmStudioClient;

/**
 * Subjective quality checks that need an LLM:
 *   - tone consistency (formal/informal mixing) — warning
 *   - clarity of paragraphs — info
 *
 * Single LLM call returns both, schema-pinned to a strict shape so the result
 * is always parseable and the orchestrator never sees garbage.
 */
class LlmQualityChecker
{
    private const SCHEMA_NAME = 'AiEditorialQualityResult';

    private const SYSTEM_PROMPT = <<<TXT
You are an editorial quality reviewer for a TYPO3 CMS.
Given a page's title and body content, identify subjective quality issues.

Return a JSON object with one field "issues" — an array of objects, each shaped:
{
  "kind": "tone" | "clarity",
  "severity": "info" | "warning",
  "message": "short editor-facing explanation, max 200 chars",
  "location": "verbatim quote from the source, max 80 chars, or empty"
}

Rules:
  - "tone": flag inconsistent register (mixing formal "Sie" with informal "du", or sudden shifts in formality between paragraphs). Severity: warning.
  - "clarity": flag paragraphs that are confusing, contradictory, or contain unexplained jargon. Severity: info.
  - At most 5 total issues. Skip the section entirely if the content is fine.
  - Match the page's language: do not translate the "message" field. German source → German message, English source → English message.
  - The "location" field must contain a verbatim substring from the source so the editor can find the offending passage.
TXT;

    public function __construct(
        private readonly LmStudioClient $client,
    ) {
    }

    /**
     * @return list<array{kind: string, severity: string, message: string, location?: string}>
     *
     * @throws LmStudioException For connectivity / model errors. Garbage LLM output is treated as empty.
     */
    public function check(string $title, string $body): array
    {
        $cleanBody = $this->extractText($body);
        $cleanTitle = trim($title);

        if ($cleanBody === '' && $cleanTitle === '') {
            return [];
        }

        $userMessage = sprintf(
            "PAGE TITLE:\n%s\n\nPAGE CONTENT:\n%s",
            $cleanTitle !== '' ? $cleanTitle : '(no title)',
            $cleanBody !== '' ? $cleanBody : '(no body content)',
        );

        $payload = $this->client->chatJsonSchema(
            [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userMessage],
            ],
            self::SCHEMA_NAME,
            [
                'type' => 'object',
                'properties' => [
                    'issues' => [
                        'type' => 'array',
                        'maxItems' => 5,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'kind' => ['type' => 'string', 'enum' => ['tone', 'clarity']],
                                'severity' => ['type' => 'string', 'enum' => ['info', 'warning']],
                                'message' => ['type' => 'string', 'maxLength' => 200],
                                'location' => ['type' => 'string', 'maxLength' => 80],
                            ],
                            'required' => ['kind', 'severity', 'message'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['issues'],
                'additionalProperties' => false,
            ],
            ['temperature' => 0.2],
        );

        $raw = is_array($payload['issues'] ?? null) ? $payload['issues'] : [];
        return $this->normalise($raw);
    }

    /**
     * @param array<int, mixed> $raw
     * @return list<array{kind: string, severity: string, message: string, location?: string}>
     */
    private function normalise(array $raw): array
    {
        $allowedKinds = ['tone', 'clarity'];
        $allowedSeverities = ['info', 'warning'];

        $result = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $kind = (string)($entry['kind'] ?? '');
            $severity = (string)($entry['severity'] ?? '');
            $message = trim((string)($entry['message'] ?? ''));
            if ($message === '' || !in_array($kind, $allowedKinds, true) || !in_array($severity, $allowedSeverities, true)) {
                continue;
            }
            $flag = [
                'kind' => $kind,
                'severity' => $severity,
                'message' => mb_substr($message, 0, 200),
            ];
            $location = trim((string)($entry['location'] ?? ''));
            if ($location !== '') {
                $flag['location'] = mb_substr($location, 0, 80);
            }
            $result[] = $flag;
        }
        return array_slice($result, 0, 5);
    }

    private function extractText(string $body): string
    {
        $stripped = strip_tags($body);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $decoded) ?? '';
        $trimmed = trim($collapsed);
        if (mb_strlen($trimmed) > 8000) {
            $trimmed = mb_substr($trimmed, 0, 8000);
        }
        return $trimmed;
    }
}
