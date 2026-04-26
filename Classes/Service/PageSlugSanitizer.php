<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use TYPO3\CMS\Core\DataHandling\SlugHelper;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Pipes a slug candidate through TYPO3's SlugHelper for the pages.slug field
 * and enforces a length cap with word-boundary backoff.
 *
 * Always run LLM-suggested slugs through this — the LLM might emit unicode
 * punctuation, emoji, leading/trailing whitespace, or inconsistent casing.
 * SlugHelper turns all of that into the canonical lowercase-hyphenated form
 * that TYPO3 expects.
 *
 * Output for the pages.slug field is prefixed with "/" (TYPO3 convention).
 * The length cap applies to the path component, not the leading slash.
 */
class PageSlugSanitizer
{
    public const DEFAULT_MAX_LENGTH = 60;
    public const TABLE = 'pages';
    public const FIELD = 'slug';

    /**
     * @param string $candidate Raw slug candidate (typically LLM output).
     * @param int    $maxLength Maximum slug length excluding the leading "/".
     */
    public function sanitize(string $candidate, int $maxLength = self::DEFAULT_MAX_LENGTH): string
    {
        $sanitized = $this->sanitizeWithSlugHelper($candidate);

        // SlugHelper for pages.slug always prepends "/" — work on the path only,
        // then re-prepend.
        $hasPrefix = str_starts_with($sanitized, '/');
        $path = $hasPrefix ? substr($sanitized, 1) : $sanitized;

        if ($maxLength > 0 && mb_strlen($path) > $maxLength) {
            $path = $this->trimAtWordBoundary($path, $maxLength);
        }

        // Strip leading/trailing dashes left over from trimming or sanitization.
        $path = trim($path, '-/');

        if ($path === '') {
            return '';
        }

        return $hasPrefix ? '/' . $path : $path;
    }

    /**
     * Test seam: lets unit tests substitute a stub by overriding this method,
     * without needing a fully-bootstrapped TYPO3 container.
     */
    protected function sanitizeWithSlugHelper(string $candidate): string
    {
        $tcaConfig = $GLOBALS['TCA'][self::TABLE]['columns'][self::FIELD]['config'] ?? [];
        // SlugHelper requires at minimum a fallbackCharacter; default to "-".
        $tcaConfig['fallbackCharacter'] = $tcaConfig['fallbackCharacter'] ?? '-';
        $tcaConfig['generatorOptions'] = $tcaConfig['generatorOptions'] ?? [];

        $helper = GeneralUtility::makeInstance(
            SlugHelper::class,
            self::TABLE,
            self::FIELD,
            $tcaConfig,
        );
        return $helper->sanitize($candidate);
    }

    private function trimAtWordBoundary(string $value, int $max): string
    {
        $cut = mb_substr($value, 0, $max);
        $lastDash = mb_strrpos($cut, '-');
        if ($lastDash !== false && $lastDash >= (int)($max * 0.5)) {
            return mb_substr($cut, 0, $lastDash);
        }
        return $cut;
    }
}
