<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\LmStudioClient;
use Kairos\AiEditorialHelper\Service\PageSlugSanitizer;
use Kairos\AiEditorialHelper\Service\SlugSuggesterService;
use PHPUnit\Framework\TestCase;

final class SlugSuggesterServiceTest extends TestCase
{
    public function testReturnsSanitizedSlug(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['slug' => 'cycling-guide']);

        $sanitizer = $this->createMock(PageSlugSanitizer::class);
        $sanitizer->expects(self::once())
            ->method('sanitize')
            ->with('cycling-guide', 60)
            ->willReturn('/cycling-guide');

        $service = new SlugSuggesterService($client, $sanitizer);
        self::assertSame('/cycling-guide', $service->generate('Cycling for Beginners'));
    }

    public function testPassesCustomMaxLengthToSanitizer(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['slug' => 'short']);

        $sanitizer = $this->createMock(PageSlugSanitizer::class);
        $sanitizer->expects(self::once())
            ->method('sanitize')
            ->with('short', 30)
            ->willReturn('/short');

        $service = new SlugSuggesterService($client, $sanitizer);
        $service->generate('Title', '', 30);
    }

    public function testEmojiInLlmOutputIsAlwaysSanitized(): void
    {
        // The LLM may break the schema regex pattern (it's a hint, not a hard constraint
        // for many local models). The sanitizer is the LAST line of defense.
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['slug' => 'cycling 🚴 guide!']);

        $sanitizer = $this->createMock(PageSlugSanitizer::class);
        $sanitizer->expects(self::once())
            ->method('sanitize')
            ->with('cycling 🚴 guide!', 60)
            ->willReturn('/cycling-guide');

        $service = new SlugSuggesterService($client, $sanitizer);
        self::assertSame('/cycling-guide', $service->generate('Cycling Guide'));
    }

    public function testIncludesContentInPromptWhenProvided(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(
                self::callback(function (array $messages): bool {
                    self::assertSame('system', $messages[0]['role']);
                    self::assertStringContainsString('SEO URL slug generator', $messages[0]['content']);
                    self::assertStringContainsString('Cycling Tips', $messages[1]['content']);
                    self::assertStringContainsString('Bavaria countryside', $messages[1]['content']);
                    self::assertStringNotContainsString('<', $messages[1]['content']);
                    return true;
                }),
                self::equalTo('AiEditorialSlugResult'),
                self::callback(function (array $schema): bool {
                    self::assertSame('object', $schema['type']);
                    self::assertContains('slug', $schema['required']);
                    self::assertSame('string', $schema['properties']['slug']['type']);
                    self::assertSame('^[a-z0-9-]+$', $schema['properties']['slug']['pattern']);
                    return true;
                }),
                self::anything(),
            )
            ->willReturn(['slug' => 'bavaria-cycling']);

        $sanitizer = $this->createMock(PageSlugSanitizer::class);
        $sanitizer->method('sanitize')->willReturnArgument(0);

        $service = new SlugSuggesterService($client, $sanitizer);
        $service->generate('Cycling Tips', '<p>Routes through the Bavaria countryside.</p>');
    }

    public function testThrowsOnEmptyTitle(): void
    {
        $service = new SlugSuggesterService(
            $this->createMock(LmStudioClient::class),
            $this->createMock(PageSlugSanitizer::class),
        );

        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_INVALID_RESPONSE);
        $service->generate('   ');
    }

    public function testThrowsWhenSanitizedSlugIsEmpty(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['slug' => '🎉🎉🎉']);

        $sanitizer = $this->createMock(PageSlugSanitizer::class);
        $sanitizer->method('sanitize')->willReturn('');

        $service = new SlugSuggesterService($client, $sanitizer);
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_INVALID_RESPONSE);
        $service->generate('Title');
    }

    public function testThrowsWhenLlmReturnsOnlyDelimiters(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['slug' => '---']);

        $sanitizer = $this->createMock(PageSlugSanitizer::class);
        $sanitizer->method('sanitize')->willReturn('/---');

        $service = new SlugSuggesterService($client, $sanitizer);
        $this->expectException(LmStudioException::class);
        $service->generate('Title');
    }

    public function testPropagatesUnreachableException(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willThrowException(
            new LmStudioException('refused', LmStudioException::CODE_UNREACHABLE),
        );

        $service = new SlugSuggesterService(
            $client,
            $this->createMock(PageSlugSanitizer::class),
        );

        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_UNREACHABLE);
        $service->generate('Title');
    }

    public function testSchemaCapIsBelowPublicMaxLength(): void
    {
        // Verify the schema's maxLength leaves headroom (at least 5 chars) for
        // the sanitizer's word-boundary backoff. Regression from cycle 1 learning.
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(function (array $schema): bool {
                    $cap = $schema['properties']['slug']['maxLength'];
                    self::assertSame(60 - 5, $cap, 'Schema cap must be public cap minus 5');
                    return true;
                }),
                self::anything(),
            )
            ->willReturn(['slug' => 'x']);

        $sanitizer = $this->createMock(PageSlugSanitizer::class);
        $sanitizer->method('sanitize')->willReturn('/x');

        $service = new SlugSuggesterService($client, $sanitizer);
        $service->generate('Title');
    }
}
