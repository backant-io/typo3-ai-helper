<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;

/**
 * Suggests up to 3 sys_category matches for a TYPO3 page based on its content.
 *
 * Reads existing top-level categories from the repository, asks the LLM to
 * pick the best matches with a confidence score, and returns enriched
 * suggestions (uid + title from the canonical record, confidence from the
 * LLM, and an optional reason).
 *
 * Falls back gracefully when:
 *   - no categories exist → empty list, no LLM call.
 *   - LLM picks UIDs that don't match any known category → silently filtered out.
 *   - LLM returns garbage → empty list, but a typed exception still propagates
 *     for unreachable / no-model errors so the UI can surface them.
 */
class CategorySuggesterService
{
    public const MAX_SUGGESTIONS = 3;
    public const MIN_CONFIDENCE = 0.0;
    public const MAX_CONFIDENCE = 1.0;

    private const SCHEMA_NAME = 'AiEditorialCategoryResult';

    private const SYSTEM_PROMPT = <<<TXT
You are a content classifier for a TYPO3 CMS.
Given a list of available categories and a page's title + content, pick the
0–3 best-matching categories. Use ONLY the category UIDs from the provided
list — do not invent new categories.

Rules:
  - Return at most 3 suggestions, ranked by relevance (highest first).
  - "confidence" is between 0.0 and 1.0. Only include suggestions ≥ 0.5.
  - If nothing matches well, return an empty array.
  - Match the page's domain language: a German-language page about cooking
    should still match an English-titled "Recipes" category if that's the best fit.
  - Output: an array of objects with fields {uid: int, confidence: float}.
TXT;

    public function __construct(
        private readonly LmStudioClient $client,
        private readonly CategoryRepository $repository,
    ) {
    }

    /**
     * @param string $title    Page title.
     * @param string $body     Plain-text body content (HTML will be stripped).
     * @return list<array{uid: int, title: string, confidence: float}>
     *
     * @throws LmStudioException on connectivity / model errors. Garbage LLM
     *                            output is treated as an empty list, not an error.
     */
    public function suggest(string $title, string $body): array
    {
        $categories = $this->repository->findTopLevelCategories();
        if ($categories === []) {
            return [];
        }

        $cleanTitle = trim($title);
        $cleanBody = $this->extractText($body);
        if ($cleanTitle === '' && $cleanBody === '') {
            return [];
        }

        $catalog = $this->buildCatalog($categories);
        $userMessage = sprintf(
            "AVAILABLE CATEGORIES:\n%s\n\nPAGE TITLE:\n%s\n\nPAGE CONTENT:\n%s",
            $catalog,
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
                    'suggestions' => [
                        'type' => 'array',
                        'maxItems' => self::MAX_SUGGESTIONS,
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'uid' => ['type' => 'integer'],
                                'confidence' => ['type' => 'number'],
                            ],
                            'required' => ['uid', 'confidence'],
                            'additionalProperties' => false,
                        ],
                    ],
                ],
                'required' => ['suggestions'],
                'additionalProperties' => false,
            ],
            ['temperature' => 0.1],
        );

        $raw = is_array($payload['suggestions'] ?? null) ? $payload['suggestions'] : [];
        return $this->enrichAndFilter($raw, $categories);
    }

    /**
     * @param list<array{uid: int, title: string, description: string}> $categories
     */
    private function buildCatalog(array $categories): string
    {
        $lines = [];
        foreach ($categories as $cat) {
            $lines[] = sprintf(
                '- uid=%d title="%s"%s',
                $cat['uid'],
                $cat['title'],
                $cat['description'] !== '' ? ' description="' . mb_substr($cat['description'], 0, 200) . '"' : '',
            );
        }
        return implode("\n", $lines);
    }

    /**
     * @param array<int, mixed> $raw
     * @param list<array{uid: int, title: string, description: string}> $categories
     * @return list<array{uid: int, title: string, confidence: float}>
     */
    private function enrichAndFilter(array $raw, array $categories): array
    {
        $byUid = [];
        foreach ($categories as $cat) {
            $byUid[$cat['uid']] = $cat;
        }

        $result = [];
        $seen = [];
        foreach ($raw as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $uid = (int)($entry['uid'] ?? 0);
            if ($uid <= 0 || !isset($byUid[$uid]) || isset($seen[$uid])) {
                continue;
            }
            $confidence = (float)($entry['confidence'] ?? 0.0);
            $confidence = max(self::MIN_CONFIDENCE, min(self::MAX_CONFIDENCE, $confidence));
            if ($confidence < 0.5) {
                continue;
            }
            $seen[$uid] = true;
            $result[] = [
                'uid' => $uid,
                'title' => $byUid[$uid]['title'],
                'confidence' => $confidence,
            ];
        }

        usort($result, static fn (array $a, array $b): int => $b['confidence'] <=> $a['confidence']);

        return array_slice($result, 0, self::MAX_SUGGESTIONS);
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
