<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;

/**
 * Suggests an SEO-friendly URL slug for a TYPO3 page.
 *
 * Difference vs TYPO3 core's SlugHelper::generate():
 *   - Core generates a *literal* slug from the title ("Über uns" → "ueber-uns").
 *   - This service uses an LLM to produce a *semantic* slug, i.e. one that
 *     captures the page's intent rather than transliterating its title
 *     ("Über uns" → "/about" if site is multilingual; long article title
 *     compressed to its essence).
 *
 * The LLM output is ALWAYS piped through {@see PageSlugSanitizer} — never
 * trust the LLM to produce safe characters or honor the length cap.
 */
class SlugSuggesterService
{
    public const DEFAULT_MAX_LENGTH = 60;

    private const SCHEMA_NAME = 'AiEditorialSlugResult';

    private const SYSTEM_PROMPT = <<<TXT
You are an SEO URL slug generator for a TYPO3 CMS.
Given a page title and optional content, return a JSON object with one field:
  - "slug": a concise, lowercase, hyphen-separated path segment.

Rules:
  - Capture the page's intent. A short verbose title can be condensed
    (e.g. "The Complete Guide to Cycling for Absolute Beginners" → "cycling-guide").
  - Prefer English when the source is German and the slug should be
    multilingual-friendly; otherwise match the source language.
  - Use only ASCII letters, digits, and hyphens. No spaces, no leading/
    trailing hyphens, no slashes, no unicode characters.
  - Keep it under 50 characters when possible.
  - No file extensions, no query strings, no underscores.
TXT;

    public function __construct(
        private readonly LmStudioClient $client,
        private readonly PageSlugSanitizer $sanitizer,
    ) {
    }

    /**
     * @param string $title    Page title (required — empty title is rejected).
     * @param string $content  Optional plain-text content for additional context.
     * @param int    $maxLength Maximum slug length (excluding the leading "/").
     *
     * @throws LmStudioException When the LLM is unreachable or returns an empty/unusable slug.
     */
    public function generate(string $title, string $content = '', int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        $cleanTitle = trim($title);
        if ($cleanTitle === '') {
            throw new LmStudioException(
                'Cannot suggest a slug: page has no title.',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }

        $cleanContent = $this->extractText($content);
        $userMessage = sprintf(
            "PAGE TITLE: %s\n\nPAGE CONTENT:\n%s",
            $cleanTitle,
            $cleanContent !== '' ? $cleanContent : '(no body content)',
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
                    'slug' => [
                        'type' => 'string',
                        // Schema cap ≤ public cap so post-trim has room to back off
                        // to a hyphen boundary if the model lands mid-segment.
                        'maxLength' => max(10, $maxLength - 5),
                        'pattern' => '^[a-z0-9-]+$',
                    ],
                ],
                'required' => ['slug'],
                'additionalProperties' => false,
            ],
            ['temperature' => 0.2],
        );

        $candidate = (string)($payload['slug'] ?? '');
        $sanitized = $this->sanitizer->sanitize($candidate, $maxLength);

        if (trim($sanitized, '/-') === '') {
            throw new LmStudioException(
                'LM Studio returned an unusable slug after sanitization.',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }

        return $sanitized;
    }

    /**
     * Strip HTML, decode entities, collapse whitespace, cap length so the prompt
     * stays small. Slug suggestion only needs the gist of the content.
     */
    private function extractText(string $content): string
    {
        $stripped = strip_tags($content);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $decoded) ?? '';
        $trimmed = trim($collapsed);
        if (mb_strlen($trimmed) > 4000) {
            $trimmed = mb_substr($trimmed, 0, 4000);
        }
        return $trimmed;
    }
}
