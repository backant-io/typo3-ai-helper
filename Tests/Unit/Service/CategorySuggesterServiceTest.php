<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\CategoryRepository;
use Kairos\AiEditorialHelper\Service\CategorySuggesterService;
use Kairos\AiEditorialHelper\Service\LmStudioClient;
use PHPUnit\Framework\TestCase;

final class CategorySuggesterServiceTest extends TestCase
{
    public function testReturnsEmptyArrayWhenNoCategoriesExist(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([]);

        $client = $this->createMock(LmStudioClient::class);
        // Important: with no categories, we must NOT call the LLM at all.
        $client->expects(self::never())->method('chatJsonSchema');

        $service = new CategorySuggesterService($client, $repo);
        self::assertSame([], $service->suggest('Title', 'Body content'));
    }

    public function testReturnsEmptyArrayWhenTitleAndBodyAreEmpty(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'Foo', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::never())->method('chatJsonSchema');

        $service = new CategorySuggesterService($client, $repo);
        self::assertSame([], $service->suggest('   ', '<p>   </p>'));
    }

    public function testReturnsEnrichedSuggestionsRankedByConfidence(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'Cycling', 'description' => 'Sport on two wheels'],
            ['uid' => 2, 'title' => 'Cooking', 'description' => ''],
            ['uid' => 3, 'title' => 'Travel', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'suggestions' => [
                ['uid' => 3, 'confidence' => 0.62],
                ['uid' => 1, 'confidence' => 0.95],
                ['uid' => 2, 'confidence' => 0.40], // below threshold — filtered
            ],
        ]);

        $service = new CategorySuggesterService($client, $repo);
        $result = $service->suggest('Bavaria Cycling Routes', 'Long-form post about Bavaria.');

        self::assertCount(2, $result);
        self::assertSame(['uid' => 1, 'title' => 'Cycling', 'confidence' => 0.95], $result[0]);
        self::assertSame(['uid' => 3, 'title' => 'Travel', 'confidence' => 0.62], $result[1]);
    }

    public function testFiltersUnknownUidsFromLlmResponse(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'Cycling', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        // LLM hallucinates non-existent UIDs.
        $client->method('chatJsonSchema')->willReturn([
            'suggestions' => [
                ['uid' => 999, 'confidence' => 0.99],
                ['uid' => 1, 'confidence' => 0.85],
                ['uid' => 0, 'confidence' => 0.80],
                ['uid' => -5, 'confidence' => 0.70],
            ],
        ]);

        $service = new CategorySuggesterService($client, $repo);
        $result = $service->suggest('Title', 'body');

        self::assertCount(1, $result);
        self::assertSame(1, $result[0]['uid']);
    }

    public function testCapsAtMaxSuggestions(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'A', 'description' => ''],
            ['uid' => 2, 'title' => 'B', 'description' => ''],
            ['uid' => 3, 'title' => 'C', 'description' => ''],
            ['uid' => 4, 'title' => 'D', 'description' => ''],
            ['uid' => 5, 'title' => 'E', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'suggestions' => [
                ['uid' => 1, 'confidence' => 0.9],
                ['uid' => 2, 'confidence' => 0.85],
                ['uid' => 3, 'confidence' => 0.8],
                ['uid' => 4, 'confidence' => 0.75],
                ['uid' => 5, 'confidence' => 0.7],
            ],
        ]);

        $service = new CategorySuggesterService($client, $repo);
        $result = $service->suggest('Title', 'body');

        self::assertCount(CategorySuggesterService::MAX_SUGGESTIONS, $result);
    }

    public function testDeduplicatesRepeatedUids(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'X', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'suggestions' => [
                ['uid' => 1, 'confidence' => 0.9],
                ['uid' => 1, 'confidence' => 0.7],
            ],
        ]);

        $service = new CategorySuggesterService($client, $repo);
        $result = $service->suggest('Title', 'body');

        self::assertCount(1, $result);
        self::assertSame(0.9, $result[0]['confidence']);
    }

    public function testClampsConfidenceToZeroOneRange(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'A', 'description' => ''],
            ['uid' => 2, 'title' => 'B', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'suggestions' => [
                ['uid' => 1, 'confidence' => 1.5],   // clamped to 1.0
                ['uid' => 2, 'confidence' => -0.4],  // clamped to 0.0 → below threshold → filtered
            ],
        ]);

        $service = new CategorySuggesterService($client, $repo);
        $result = $service->suggest('Title', 'body');

        self::assertCount(1, $result);
        self::assertSame(1.0, $result[0]['confidence']);
    }

    public function testReturnsEmptyArrayWhenLlmResponseShapeIsWrong(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'A', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        // LLM returns garbage at the array level — service should treat as empty, not throw.
        $client->method('chatJsonSchema')->willReturn(['suggestions' => 'not-an-array']);

        $service = new CategorySuggesterService($client, $repo);
        self::assertSame([], $service->suggest('Title', 'body'));
    }

    public function testPropagatesUnreachableException(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'A', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willThrowException(
            new LmStudioException('refused', LmStudioException::CODE_UNREACHABLE),
        );

        $service = new CategorySuggesterService($client, $repo);
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_UNREACHABLE);
        $service->suggest('Title', 'body');
    }

    public function testIncludesCategoryListAndContentInPrompt(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'Cycling', 'description' => 'Sport on two wheels'],
            ['uid' => 2, 'title' => 'Cooking', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(
                self::callback(function (array $messages): bool {
                    self::assertSame('system', $messages[0]['role']);
                    self::assertStringContainsString('content classifier', $messages[0]['content']);

                    $userMsg = $messages[1]['content'];
                    self::assertStringContainsString('uid=1', $userMsg);
                    self::assertStringContainsString('Cycling', $userMsg);
                    self::assertStringContainsString('Sport on two wheels', $userMsg);
                    self::assertStringContainsString('uid=2', $userMsg);
                    self::assertStringContainsString('Cooking', $userMsg);
                    self::assertStringContainsString('Bavaria', $userMsg);
                    self::assertStringNotContainsString('<', $userMsg);
                    return true;
                }),
                self::equalTo('AiEditorialCategoryResult'),
                self::callback(function (array $schema): bool {
                    self::assertSame('object', $schema['type']);
                    self::assertContains('suggestions', $schema['required']);
                    self::assertSame(3, $schema['properties']['suggestions']['maxItems']);
                    self::assertSame('integer', $schema['properties']['suggestions']['items']['properties']['uid']['type']);
                    self::assertSame('number', $schema['properties']['suggestions']['items']['properties']['confidence']['type']);
                    return true;
                }),
                self::anything(),
            )
            ->willReturn(['suggestions' => []]);

        $service = new CategorySuggesterService($client, $repo);
        $service->suggest('Bavaria Routes', '<p>Cycling routes through Bavaria.</p>');
    }

    public function testEmptyLlmSuggestionsReturnsEmptyArray(): void
    {
        $repo = $this->createMock(CategoryRepository::class);
        $repo->method('findTopLevelCategories')->willReturn([
            ['uid' => 1, 'title' => 'A', 'description' => ''],
        ]);

        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['suggestions' => []]);

        $service = new CategorySuggesterService($client, $repo);
        self::assertSame([], $service->suggest('Title', 'body content'));
    }
}
