<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;

/**
 * Generates a 1–2-sentence teaser for a TYPO3 page, intended for the
 * pages.abstract field or any list-view rendering that needs a short blurb.
 *
 * Hard cap: 240 chars. Output ALWAYS ends on sentence-ending punctuation
 * (`.`, `!`, `?`) — if the LLM produces a partial trailing sentence we
 * trim back to the previous full one.
 */
class TeaserService
{
    public const MAX_LENGTH = 240;

    private const SCHEMA_MAX = 235;
    private const SCHEMA_NAME = 'AiEditorialTeaserResult';

    private const SYSTEM_PROMPT = <<<TXT
You are an editorial copywriter assisting an editor in a TYPO3 CMS.
Given a page's title and body content, return a JSON object with one field:
  - "teaser": a 1–2 sentence excerpt suitable for a list view or RSS feed.

Rules:
  - Match the language of the source content (German source → German teaser, English source → English teaser).
  - Maximum 235 characters. Stay safely under the cap.
  - Always end on a complete sentence — punctuation `.`, `!` or `?`.
  - Do not invent facts. Stay faithful to the source.
  - No leading/trailing quotes. No lead-in like "This page is about…".
  - No markdown, no HTML.
TXT;

    public function __construct(
        private readonly LmStudioClient $client,
    ) {
    }

    /**
     * @throws LmStudioException When LM Studio is unreachable or returns an unusable teaser.
     */
    public function generate(string $title, string $body): string
    {
        $cleanBody = $this->extractText($body);
        $cleanTitle = trim($title);

        if ($cleanBody === '' && $cleanTitle === '') {
            throw new LmStudioException(
                'Cannot generate a teaser: page has no title and no content.',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
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
                    'teaser' => ['type' => 'string', 'maxLength' => self::SCHEMA_MAX],
                ],
                'required' => ['teaser'],
                'additionalProperties' => false,
            ],
            ['temperature' => 0.4],
        );

        $teaser = $this->trimToCompleteSentence((string)($payload['teaser'] ?? ''));

        if ($teaser === '') {
            throw new LmStudioException(
                'LM Studio returned an empty teaser.',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }

        return $teaser;
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

    /**
     * Hard-cap at MAX_LENGTH and ensure the result ends on sentence-ending
     * punctuation. Strategy:
     *   1. Strip surrounding whitespace and quotes.
     *   2. Hard-cut at MAX_LENGTH if needed.
     *   3. If the result already ends on .!? — done.
     *   4. Else find the last .!? within the (possibly truncated) string and
     *      truncate after it, provided we keep at least 50% of the cap.
     *   5. Otherwise back off to the last word boundary and append ".".
     */
    private function trimToCompleteSentence(string $value): string
    {
        $value = trim(preg_replace('/\s+/u', ' ', $value) ?? '');
        $value = trim($value, "\"'“”‘’");
        if ($value === '') {
            return '';
        }

        if (mb_strlen($value) > self::MAX_LENGTH) {
            $value = mb_substr($value, 0, self::MAX_LENGTH);
        }

        if ($this->endsOnSentenceEnd($value)) {
            return $value;
        }

        // Find the last sentence-ending punctuation in the (truncated) string.
        $lastEnd = $this->lastSentenceEndPosition($value);
        if ($lastEnd !== null && $lastEnd >= (int)(self::MAX_LENGTH * 0.5)) {
            return mb_substr($value, 0, $lastEnd + 1);
        }

        // No usable sentence ending — back off to a word boundary and append a period.
        $lastSpace = mb_strrpos($value, ' ');
        if ($lastSpace !== false && $lastSpace >= (int)(self::MAX_LENGTH * 0.5)) {
            $value = mb_substr($value, 0, $lastSpace);
        }
        $value = rtrim($value, " \t\n\r\0\x0B,;:-—–|");
        if ($value === '') {
            return '';
        }

        return $value . '.';
    }

    private function endsOnSentenceEnd(string $value): bool
    {
        $last = mb_substr($value, -1);
        return $last === '.' || $last === '!' || $last === '?' || $last === '…';
    }

    private function lastSentenceEndPosition(string $value): ?int
    {
        // Iterate from the end, return position of the latest .!?
        $length = mb_strlen($value);
        for ($i = $length - 1; $i >= 0; $i--) {
            $char = mb_substr($value, $i, 1);
            if ($char === '.' || $char === '!' || $char === '?' || $char === '…') {
                return $i;
            }
        }
        return null;
    }
}
