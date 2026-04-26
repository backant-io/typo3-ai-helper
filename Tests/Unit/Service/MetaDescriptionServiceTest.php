<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\LmStudioClient;
use Kairos\AiEditorialHelper\Service\MetaDescriptionService;
use PHPUnit\Framework\TestCase;

final class MetaDescriptionServiceTest extends TestCase
{
    public function testReturnsTrimmedFieldsFromLlmResponse(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->willReturn([
                'metaDescription' => '  Discover the joy of cycling on quiet country roads.  ',
                'seoTitle' => '  Cycling Guide  ',
            ]);

        $service = new MetaDescriptionService($client);
        $result = $service->generate('Cycling for Beginners', '<p>Lots of helpful tips.</p>');

        self::assertSame('Discover the joy of cycling on quiet country roads.', $result['metaDescription']);
        self::assertSame('Cycling Guide', $result['seoTitle']);
    }

    public function testEnforcesMetaDescriptionMaxLength(): void
    {
        $longMeta = str_repeat('Lorem ipsum dolor sit amet. ', 20);
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'metaDescription' => $longMeta,
            'seoTitle' => 'Title',
        ]);

        $service = new MetaDescriptionService($client);
        $result = $service->generate('Title', 'body');

        self::assertLessThanOrEqual(
            MetaDescriptionService::META_DESCRIPTION_MAX,
            mb_strlen($result['metaDescription']),
        );
        self::assertSame(' ', '' === $result['metaDescription'] ? '' : ' '); // sanity
        self::assertNotEmpty($result['metaDescription']);
    }

    public function testEnforcesSeoTitleMaxLength(): void
    {
        $longTitle = str_repeat('Cycling tips for beginners ', 5);
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'metaDescription' => 'short meta',
            'seoTitle' => $longTitle,
        ]);

        $service = new MetaDescriptionService($client);
        $result = $service->generate('t', 'b');

        self::assertLessThanOrEqual(
            MetaDescriptionService::SEO_TITLE_MAX,
            mb_strlen($result['seoTitle']),
        );
    }

    public function testTrimAvoidsCuttingMidWord(): void
    {
        // Length 60 max for SEO title. Build a string where cap=60 lands mid-word.
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'metaDescription' => 'meta description here',
            'seoTitle' => 'Cycling Adventures Across Europe And Beyond — A Comprehensive Guide',
        ]);

        $service = new MetaDescriptionService($client);
        $result = $service->generate('t', 'b');

        // Should not end mid-word and not have a trailing dash/comma.
        self::assertDoesNotMatchRegularExpression('/[\s,;:\-—–]$/u', $result['seoTitle']);
        self::assertLessThanOrEqual(MetaDescriptionService::SEO_TITLE_MAX, mb_strlen($result['seoTitle']));
    }

    public function testStripsHtmlAndCollapsesWhitespaceInBody(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(
                self::callback(function (array $messages): bool {
                    self::assertSame('system', $messages[0]['role']);
                    self::assertStringContainsString('SEO copywriter', $messages[0]['content']);

                    $userMsg = $messages[1]['content'];
                    self::assertStringNotContainsString('<', $userMsg);
                    self::assertStringContainsString('Hello world.', $userMsg);
                    // No multiple spaces after collapse.
                    self::assertDoesNotMatchRegularExpression('/  +/', $userMsg);
                    return true;
                }),
                self::equalTo('AiEditorialMetaResult'),
                self::callback(function (array $schema): bool {
                    self::assertSame('object', $schema['type']);
                    self::assertContains('metaDescription', $schema['required']);
                    self::assertContains('seoTitle', $schema['required']);
                    self::assertFalse($schema['additionalProperties']);
                    self::assertSame(155, $schema['properties']['metaDescription']['maxLength']);
                    self::assertSame(55, $schema['properties']['seoTitle']['maxLength']);
                    return true;
                }),
                self::anything(),
            )
            ->willReturn(['metaDescription' => 'm', 'seoTitle' => 's']);

        $service = new MetaDescriptionService($client);
        $service->generate('Title', "<p>Hello   world.</p>\n\n  <strong>More</strong>");
    }

    public function testCleansUpMidWordEndingAtCap(): void
    {
        // The LLM's schema-bound output landed exactly at the cap mid-word.
        // After post-trim, the result must end on a word boundary, no longer mid-syllable.
        $client = $this->createMock(LmStudioClient::class);
        // 60 chars exactly, ends mid-word "Barrierefreihe"
        $atCapMidWord = 'Über uns | Berliner Digitalagentur für TYPO3, Barrierefreihe';
        $client->method('chatJsonSchema')->willReturn([
            'metaDescription' => 'A short meta',
            'seoTitle' => $atCapMidWord,
        ]);

        $service = new MetaDescriptionService($client);
        $result = $service->generate('t', 'b');

        self::assertLessThanOrEqual(MetaDescriptionService::SEO_TITLE_MAX, mb_strlen($result['seoTitle']));
        self::assertStringNotContainsString('Barrierefreihe', $result['seoTitle']);
        self::assertDoesNotMatchRegularExpression('/[\s,;:|\-—–]$/u', $result['seoTitle']);
    }

    public function testThrowsOnEmptyTitleAndEmptyBody(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $service = new MetaDescriptionService($client);

        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_INVALID_RESPONSE);
        $service->generate('   ', '<p>   </p>');
    }

    public function testThrowsWhenLlmReturnsEmptyFields(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'metaDescription' => '   ',
            'seoTitle' => '',
        ]);

        $service = new MetaDescriptionService($client);
        $this->expectException(LmStudioException::class);
        $service->generate('Title', 'body content');
    }

    public function testPropagatesUnreachableException(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willThrowException(
            new LmStudioException('refused', LmStudioException::CODE_UNREACHABLE),
        );

        $service = new MetaDescriptionService($client);
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_UNREACHABLE);
        $service->generate('Title', 'Body content here.');
    }

    public function testHandlesEmptyBodyButNonEmptyTitle(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'metaDescription' => 'A page about widgets.',
            'seoTitle' => 'Widgets',
        ]);

        $service = new MetaDescriptionService($client);
        $result = $service->generate('Widgets', '');
        self::assertSame('A page about widgets.', $result['metaDescription']);
    }

    public function testGermanContentPassesThrough(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(self::callback(function (array $messages): bool {
                self::assertStringContainsString('Über uns', $messages[1]['content']);
                self::assertStringContainsString('Geschäftsbericht', $messages[1]['content']);
                return true;
            }))
            ->willReturn(['metaDescription' => 'Beschreibung der Seite.', 'seoTitle' => 'Über uns']);

        $service = new MetaDescriptionService($client);
        $result = $service->generate('Über uns', 'Geschäftsbericht 2025 mit Umlauten: ÄÖÜß.');
        self::assertSame('Über uns', $result['seoTitle']);
    }
}
