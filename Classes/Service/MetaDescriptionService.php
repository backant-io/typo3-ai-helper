<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;

/**
 * Generates an SEO meta description and title for a TYPO3 page.
 *
 * Caps:
 *   - meta description ≤ 160 chars
 *   - SEO title       ≤ 60 chars
 *
 * Both caps are enforced via the prompt AND a hard post-trim, because LLMs
 * routinely overshoot length constraints. Trim is sentence-aware where
 * possible so we don't cut off mid-word.
 */
class MetaDescriptionService
{
    public const META_DESCRIPTION_MAX = 160;
    public const SEO_TITLE_MAX = 60;

    /**
     * Schema cap is set BELOW the public cap so grammar-constrained sampling
     * still leaves room for {@see trimToLength()} to back off to a word boundary
     * when the LLM happens to land mid-word at the schema limit.
     */
    private const SCHEMA_META_MAX = 155;
    private const SCHEMA_TITLE_MAX = 55;

    private const SYSTEM_PROMPT = <<<TXT
You are an SEO copywriter assisting an editor in a TYPO3 CMS.
Given a page's title and body content, return a JSON object with two fields:
  - "metaDescription": a single-sentence search-result snippet, max 155 characters.
  - "seoTitle":        a concise click-worthy page title, max 55 characters.

Rules:
  - Match the language of the source content (German source → German output, English source → English output).
  - Stay safely under the character caps. End on a complete word, not mid-syllable.
  - No trailing ellipsis. End on punctuation or a complete word.
  - Do not include the site name, brand, or any boilerplate suffix — the editor adds those.
  - Do not invent facts that are absent from the source.
TXT;

    public function __construct(
        private readonly LmStudioClient $client,
    ) {
    }

    /**
     * @param string $title       Existing page title (may be empty).
     * @param string $body        Plain-text body content (HTML will be stripped).
     * @return array{metaDescription: string, seoTitle: string}
     *
     * @throws LmStudioException When the LLM is unreachable or returns garbage.
     */
    public function generate(string $title, string $body): array
    {
        $cleanBody = $this->extractText($body);
        if ($cleanBody === '' && trim($title) === '') {
            throw new LmStudioException(
                'Cannot generate meta description: page has no title and no content.',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }

        $userMessage = sprintf(
            "PAGE TITLE:\n%s\n\nPAGE CONTENT:\n%s",
            trim($title) !== '' ? trim($title) : '(no title)',
            $cleanBody !== '' ? $cleanBody : '(no body content)',
        );

        $payload = $this->client->chatJsonSchema(
            [
                ['role' => 'system', 'content' => self::SYSTEM_PROMPT],
                ['role' => 'user', 'content' => $userMessage],
            ],
            'AiEditorialMetaResult',
            [
                'type' => 'object',
                'properties' => [
                    'metaDescription' => ['type' => 'string', 'maxLength' => self::SCHEMA_META_MAX],
                    'seoTitle' => ['type' => 'string', 'maxLength' => self::SCHEMA_TITLE_MAX],
                ],
                'required' => ['metaDescription', 'seoTitle'],
                'additionalProperties' => false,
            ],
            ['temperature' => 0.3],
        );

        $meta = $this->trimToLength((string)($payload['metaDescription'] ?? ''), self::META_DESCRIPTION_MAX);
        $seo = $this->trimToLength((string)($payload['seoTitle'] ?? ''), self::SEO_TITLE_MAX);

        if ($meta === '' || $seo === '') {
            throw new LmStudioException(
                'LM Studio returned an empty metaDescription or seoTitle.',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }

        return ['metaDescription' => $meta, 'seoTitle' => $seo];
    }

    /**
     * Strip HTML tags, decode entities, collapse whitespace, cap length so the
     * prompt stays within reasonable token budget for small local models.
     */
    private function extractText(string $body): string
    {
        $stripped = strip_tags($body);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $decoded) ?? '';
        $trimmed = trim($collapsed);
        // 8000 chars ≈ ~2k tokens — generous for a meta description prompt.
        if (mb_strlen($trimmed) > 8000) {
            $trimmed = mb_substr($trimmed, 0, 8000);
        }
        return $trimmed;
    }

    /**
     * Enforce a hard cap and clean up the trailing edge.
     *
     * Two correction passes:
     *   1. If the value exceeds the cap, hard-cut at the cap.
     *   2. If the value ends mid-word (last char is a letter/digit AND the
     *      length is close to the cap), back off to the previous space.
     *      This handles the case where the LLM's schema-limited output stopped
     *      mid-syllable — the cap was reached during sampling, not after.
     *
     * Also strips dangling punctuation that should never end an SEO snippet.
     */
    private function trimToLength(string $value, int $max): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > $max) {
            $value = mb_substr($value, 0, $max);
        }

        $lastChar = mb_substr($value, -1);
        $endsMidWord = preg_match('/[\p{L}\p{N}]/u', $lastChar) === 1;
        $isNearCap = mb_strlen($value) >= max($max - 5, (int)($max * 0.85));

        if ($endsMidWord && $isNearCap) {
            $lastSpace = mb_strrpos($value, ' ');
            if ($lastSpace !== false && $lastSpace >= (int)($max * 0.6)) {
                $value = mb_substr($value, 0, $lastSpace);
            }
        }

        return rtrim($value, " \t\n\r\0\x0B,;:|-—–");
    }
}
