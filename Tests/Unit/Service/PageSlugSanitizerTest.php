<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Service\PageSlugSanitizer;
use PHPUnit\Framework\TestCase;

final class PageSlugSanitizerTest extends TestCase
{
    public function testStripsEmojiAndSpecialCharsViaSanitizer(): void
    {
        $sanitizer = $this->makeSanitizer(static fn (string $candidate): string => '/'
            . preg_replace('/[^a-z0-9]+/u', '-', mb_strtolower($candidate)));

        $result = $sanitizer->sanitize('Hello 🎉 World!');
        self::assertSame('/hello-world', $result);
    }

    public function testTrimsToMaxLengthAtHyphenBoundary(): void
    {
        // Stub mimics SlugHelper: returns "/<lower-hyphenated>" untouched.
        $sanitizer = $this->makeSanitizer(static fn (string $c): string => '/' . str_replace(' ', '-', strtolower($c)));

        $longTitle = 'cycling-tips-for-absolute-beginners-on-quiet-country-roads-of-bavaria';
        $result = $sanitizer->sanitize($longTitle, 30);

        self::assertLessThanOrEqual(31, mb_strlen($result)); // 30 + leading slash
        self::assertStringStartsWith('/', $result);
        // Hyphen-boundary trim: the result must end on a complete segment, never a trailing dash.
        self::assertDoesNotMatchRegularExpression('/-$/', $result);
        // The original last segment "bavaria" must be gone (not truncated mid-segment).
        self::assertStringNotContainsString('bavar', $result);
        // The result must be a prefix of the input (no creative reshaping by the trim).
        self::assertStringStartsWith($result, '/' . $longTitle);
    }

    public function testRetainsLeadingSlashFromSlugHelper(): void
    {
        $sanitizer = $this->makeSanitizer(static fn (string $c): string => '/about-us');
        self::assertSame('/about-us', $sanitizer->sanitize('About Us'));
    }

    public function testStripsTrailingHyphenAfterTrim(): void
    {
        $sanitizer = $this->makeSanitizer(static fn (string $c): string => '/foo-bar-baz-quux-quuux');

        $result = $sanitizer->sanitize('whatever', 10);
        // Whatever the trim produced, it must NOT end with a dash.
        self::assertSame('-', '-'); // sanity
        self::assertDoesNotMatchRegularExpression('/-$/', $result);
        self::assertStringStartsWith('/', $result);
    }

    public function testReturnsEmptyWhenSanitizerProducesOnlyDelimiters(): void
    {
        $sanitizer = $this->makeSanitizer(static fn (string $c): string => '/---');
        self::assertSame('', $sanitizer->sanitize('🎉🎉🎉'));
    }

    public function testHandlesAlreadyShortSlug(): void
    {
        $sanitizer = $this->makeSanitizer(static fn (string $c): string => '/about');
        self::assertSame('/about', $sanitizer->sanitize('About', 60));
    }

    public function testTrimsPathOnlyNotPrefix(): void
    {
        $sanitizer = $this->makeSanitizer(static fn (string $c): string => '/' . str_repeat('a', 100));

        $result = $sanitizer->sanitize('whatever', 10);

        self::assertStringStartsWith('/', $result);
        // The path should be ≤ 10 chars (the leading slash is not counted).
        self::assertLessThanOrEqual(11, mb_strlen($result));
    }

    public function testHonorsNoCapWhenMaxLengthIsZero(): void
    {
        $sanitizer = $this->makeSanitizer(static fn (string $c): string => '/' . str_repeat('a', 200));
        $result = $sanitizer->sanitize('whatever', 0);
        self::assertSame(201, mb_strlen($result));
    }

    /**
     * Build a PageSlugSanitizer subclass that delegates to the given closure
     * instead of touching $GLOBALS['TCA'] / GeneralUtility.
     */
    private function makeSanitizer(\Closure $sanitizeFn): PageSlugSanitizer
    {
        return new class ($sanitizeFn) extends PageSlugSanitizer {
            public function __construct(private readonly \Closure $fn)
            {
            }
            protected function sanitizeWithSlugHelper(string $candidate): string
            {
                return ($this->fn)($candidate);
            }
        };
    }
}
