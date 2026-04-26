<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service\Quality;

/**
 * Pure-function quality checks that run instantly without any LLM call.
 *
 * Issues flagged here:
 *   - Sentence length > MAX_SENTENCE_WORDS (warning)
 *   - No <h1> on the page (error)
 *   - Multiple <h1> elements (error)
 *   - Heading hierarchy skips levels, e.g. h2 → h4 (warning)
 *
 * No DI, no $GLOBALS access — just static-friendly methods so the orchestrator
 * (and tests) can construct trivially.
 */
class RuleBasedQualityChecker
{
    public const MAX_SENTENCE_WORDS = 30;

    /**
     * @return list<array{kind: string, severity: string, message: string, location?: string}>
     */
    public function check(string $html): array
    {
        $flags = [];
        $flags = array_merge($flags, $this->checkHeadingStructure($html));
        $flags = array_merge($flags, $this->checkSentenceLengths($html));
        return $flags;
    }

    /**
     * @return list<array{kind: string, severity: string, message: string, location?: string}>
     */
    public function checkHeadingStructure(string $html): array
    {
        $flags = [];
        $headings = $this->extractHeadings($html);
        $h1Count = 0;
        $previousLevel = 0;

        foreach ($headings as $heading) {
            if ($heading['level'] === 1) {
                $h1Count++;
            }

            if ($previousLevel > 0 && $heading['level'] > $previousLevel + 1) {
                $flags[] = [
                    'kind' => 'heading_structure',
                    'severity' => 'warning',
                    'message' => sprintf(
                        'Heading hierarchy skips a level: "%s" goes from h%d to h%d.',
                        $this->shortenForMessage($heading['text']),
                        $previousLevel,
                        $heading['level'],
                    ),
                    'location' => sprintf('h%d "%s"', $heading['level'], $this->shortenForMessage($heading['text'])),
                ];
            }
            $previousLevel = $heading['level'];
        }

        if ($h1Count === 0 && $headings !== []) {
            $flags[] = [
                'kind' => 'heading_structure',
                'severity' => 'error',
                'message' => 'Page has headings but no <h1>. Add a top-level heading for accessibility and SEO.',
            ];
        } elseif ($h1Count > 1) {
            $flags[] = [
                'kind' => 'heading_structure',
                'severity' => 'error',
                'message' => sprintf('Page has %d <h1> elements. Use exactly one.', $h1Count),
            ];
        }

        return $flags;
    }

    /**
     * @return list<array{kind: string, severity: string, message: string, location?: string}>
     */
    public function checkSentenceLengths(string $html): array
    {
        $text = $this->extractPlainText($html);
        if ($text === '') {
            return [];
        }

        $sentences = $this->splitSentences($text);
        $flags = [];
        foreach ($sentences as $sentence) {
            $wordCount = $this->wordCount($sentence);
            if ($wordCount > self::MAX_SENTENCE_WORDS) {
                $flags[] = [
                    'kind' => 'sentence_length',
                    'severity' => 'warning',
                    'message' => sprintf(
                        'Sentence has %d words (cap %d). Consider splitting it.',
                        $wordCount,
                        self::MAX_SENTENCE_WORDS,
                    ),
                    'location' => $this->shortenForMessage($sentence),
                ];
            }
        }
        return $flags;
    }

    /**
     * @return list<array{level: int, text: string}>
     */
    private function extractHeadings(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $doc = $this->loadHtml($html);
        $xpath = new \DOMXPath($doc);

        $found = [];
        foreach ($xpath->query('//h1 | //h2 | //h3 | //h4 | //h5 | //h6') ?: [] as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            $level = (int)substr($node->nodeName, 1);
            $found[] = ['level' => $level, 'text' => trim($node->textContent)];
        }
        return $found;
    }

    private function extractPlainText(string $html): string
    {
        $stripped = strip_tags($html);
        $decoded = html_entity_decode($stripped, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $collapsed = preg_replace('/\s+/u', ' ', $decoded) ?? '';
        return trim($collapsed);
    }

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        // Split on sentence-ending punctuation followed by whitespace + uppercase letter
        // (or end of string). Avoids splitting on abbreviations like "z.B."
        $parts = preg_split(
            '/(?<=[.!?])\s+(?=[\p{Lu}])/u',
            $text,
            -1,
            PREG_SPLIT_NO_EMPTY,
        );
        if ($parts === false) {
            return [];
        }
        return array_values(array_map('trim', $parts));
    }

    private function wordCount(string $sentence): int
    {
        $words = preg_split('/\s+/u', trim($sentence), -1, PREG_SPLIT_NO_EMPTY);
        return is_array($words) ? count($words) : 0;
    }

    private function shortenForMessage(string $text, int $max = 60): string
    {
        $clean = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
        if (mb_strlen($clean) <= $max) {
            return $clean;
        }
        return mb_substr($clean, 0, $max - 1) . '…';
    }

    private function loadHtml(string $html): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $wrapped = '<?xml encoding="UTF-8"?><quality-root>' . $html . '</quality-root>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }
}
