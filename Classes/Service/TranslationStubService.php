<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;

/**
 * Generates a starter DE↔EN translation of TYPO3 page bodytext.
 *
 * The output is a draft, not a final translation — editors review and refine.
 * The service prepends a clearly-visible [STUB] marker so this never silently
 * gets published as canonical content.
 *
 * Critical: HTML structure is preserved via DOMDocument. We do NOT feed raw
 * HTML to the LLM and hope. Text nodes are extracted, sent to the LLM as a
 * positional array, translated, and mapped back into the original DOM. Tags
 * and attributes (href, src, class, …) survive unchanged.
 *
 * Supported languages: 'de' and 'en'. Anything else raises.
 */
class TranslationStubService
{
    public const SUPPORTED_LANGUAGES = ['de', 'en'];
    public const STUB_PREFIX = '[STUB] ';

    private const SCHEMA_NAME = 'AiEditorialTranslationResult';

    /** Skip text nodes inside these elements — they don't contain translatable prose. */
    private const NON_TRANSLATABLE_PARENTS = ['script', 'style', 'code', 'pre'];

    /** Cap on number of text nodes per request — single LLM call only. */
    private const MAX_NODES_PER_BATCH = 80;

    public function __construct(
        private readonly LmStudioClient $client,
    ) {
    }

    /**
     * Translate body HTML from source to target language.
     *
     * @throws LmStudioException When LM Studio is unreachable or returns an unusable response,
     *                            or when language pair is unsupported.
     */
    public function translate(string $html, string $sourceLang, string $targetLang): string
    {
        $sourceLang = strtolower(trim($sourceLang));
        $targetLang = strtolower(trim($targetLang));

        $this->assertSupportedLanguage($sourceLang, 'source');
        $this->assertSupportedLanguage($targetLang, 'target');

        if ($sourceLang === $targetLang) {
            // No-op: same language — still mark as stub so editor knows it was processed.
            return $this->prependStubMarker($html);
        }

        if (trim($html) === '') {
            throw new LmStudioException(
                'Cannot translate: source content is empty.',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }

        $doc = $this->loadHtml($html);
        $textNodes = $this->collectTranslatableTextNodes($doc);

        if ($textNodes === []) {
            // HTML had only tags / non-text content — nothing to translate.
            return $this->prependStubMarker($html);
        }

        if (count($textNodes) > self::MAX_NODES_PER_BATCH) {
            // Too many nodes for a single call. Fail loudly rather than silently
            // truncate — editor can split the content into smaller pieces.
            throw new LmStudioException(
                sprintf(
                    'Content has too many text nodes (%d > %d). Translate in smaller chunks.',
                    count($textNodes),
                    self::MAX_NODES_PER_BATCH,
                ),
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }

        $items = [];
        foreach ($textNodes as $i => $node) {
            $items[] = ['index' => $i, 'text' => $node->nodeValue ?? ''];
        }

        $payload = $this->client->chatJsonSchema(
            [
                ['role' => 'system', 'content' => $this->buildSystemPrompt($sourceLang, $targetLang)],
                ['role' => 'user', 'content' => json_encode($items, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)],
            ],
            self::SCHEMA_NAME,
            $this->buildResponseSchema(count($items)),
            ['temperature' => 0.2],
        );

        $this->applyTranslations($textNodes, $payload['translations'] ?? []);

        $serialised = $this->serializeBody($doc);
        return $this->prependStubMarker($serialised);
    }

    /**
     * @return list<\DOMText>
     */
    private function collectTranslatableTextNodes(\DOMDocument $doc): array
    {
        $xpath = new \DOMXPath($doc);
        $textNodes = [];
        foreach ($xpath->query('//text()') ?: [] as $node) {
            if (!$node instanceof \DOMText) {
                continue;
            }
            $value = $node->nodeValue ?? '';
            if (trim($value) === '') {
                continue;
            }
            if ($this->hasNonTranslatableAncestor($node)) {
                continue;
            }
            $textNodes[] = $node;
        }
        return $textNodes;
    }

    private function hasNonTranslatableAncestor(\DOMNode $node): bool
    {
        $parent = $node->parentNode;
        while ($parent !== null) {
            if ($parent instanceof \DOMElement
                && in_array(strtolower($parent->nodeName), self::NON_TRANSLATABLE_PARENTS, true)
            ) {
                return true;
            }
            $parent = $parent->parentNode;
        }
        return false;
    }

    /**
     * @param list<\DOMText> $textNodes
     * @param array<int, mixed> $translations
     */
    private function applyTranslations(array $textNodes, array $translations): void
    {
        $byIndex = [];
        foreach ($translations as $entry) {
            if (!is_array($entry) || !isset($entry['index'], $entry['text'])) {
                continue;
            }
            $byIndex[(int)$entry['index']] = (string)$entry['text'];
        }

        foreach ($textNodes as $i => $node) {
            if (!array_key_exists($i, $byIndex)) {
                continue;
            }
            // Preserve leading/trailing whitespace from the original node so layout
            // and inter-word spacing don't shift. The LLM may have stripped them.
            $original = $node->nodeValue ?? '';
            $leading = $this->extractWhitespacePrefix($original);
            $trailing = $this->extractWhitespaceSuffix($original);
            $node->nodeValue = $leading . trim($byIndex[$i]) . $trailing;
        }
    }

    private function extractWhitespacePrefix(string $value): string
    {
        if (preg_match('/^\s+/u', $value, $m) === 1) {
            return $m[0];
        }
        return '';
    }

    private function extractWhitespaceSuffix(string $value): string
    {
        if (preg_match('/\s+$/u', $value, $m) === 1) {
            return $m[0];
        }
        return '';
    }

    private function loadHtml(string $html): \DOMDocument
    {
        $doc = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        // Wrap in a root so loadHTML doesn't auto-add <html><body>. NOIMPLIED + NODEFDTD
        // keep the document structure clean.
        $wrapped = '<?xml encoding="UTF-8"?><ai-editorial-root>' . $html . '</ai-editorial-root>';
        $doc->loadHTML($wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $doc;
    }

    private function serializeBody(\DOMDocument $doc): string
    {
        $root = $doc->getElementsByTagName('ai-editorial-root')->item(0);
        if ($root === null) {
            return '';
        }
        $result = '';
        foreach ($root->childNodes as $child) {
            $rendered = $doc->saveHTML($child);
            if (is_string($rendered)) {
                $result .= $rendered;
            }
        }
        return $result;
    }

    private function prependStubMarker(string $html): string
    {
        // Always visible: prepend a paragraph the editor can't miss.
        $marker = sprintf(
            '<p data-ai-editorial-stub="1"><strong>%sAuto-generated translation. Please review.</strong></p>',
            self::STUB_PREFIX,
        );
        return $marker . "\n" . $html;
    }

    private function buildSystemPrompt(string $sourceLang, string $targetLang): string
    {
        $sourceName = $this->languageName($sourceLang);
        $targetName = $this->languageName($targetLang);

        return <<<TXT
You are a professional translator producing a draft translation from $sourceName to $targetName.

You will receive a JSON array of objects: [{"index": int, "text": "..."}, ...].

Return a JSON object with a single key "translations" — an array of {"index": int, "text": "..."}, ONE entry per input index.

Rules:
  - Translate the "text" field from $sourceName to $targetName, faithfully.
  - Preserve the meaning. Do not summarise, expand, or editorialise.
  - Do not add or remove sentences.
  - Match the register: formal stays formal, informal stays informal.
  - For proper nouns (names, brand names): keep verbatim.
  - For numbers, dates, URLs, and code-like fragments: keep verbatim.
  - The same indices must appear in the output array, exactly once each.
TXT;
    }

    private function languageName(string $code): string
    {
        return match ($code) {
            'de' => 'German',
            'en' => 'English',
            default => $code,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function buildResponseSchema(int $itemCount): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'translations' => [
                    'type' => 'array',
                    'minItems' => $itemCount,
                    'maxItems' => $itemCount,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'index' => ['type' => 'integer'],
                            'text' => ['type' => 'string'],
                        ],
                        'required' => ['index', 'text'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['translations'],
            'additionalProperties' => false,
        ];
    }

    private function assertSupportedLanguage(string $code, string $role): void
    {
        if (!in_array($code, self::SUPPORTED_LANGUAGES, true)) {
            throw new LmStudioException(
                sprintf('Unsupported %s language: "%s". Supported: %s', $role, $code, implode(', ', self::SUPPORTED_LANGUAGES)),
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }
    }
}
