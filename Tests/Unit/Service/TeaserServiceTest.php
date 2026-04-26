<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\LmStudioClient;
use Kairos\AiEditorialHelper\Service\TeaserService;
use PHPUnit\Framework\TestCase;

final class TeaserServiceTest extends TestCase
{
    public function testReturnsLlmTeaserWhenItAlreadyEndsOnPunctuation(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'teaser' => '  A friendly guide to your first 100 km on two wheels. Includes gear, route planning, and rain prep. ',
        ]);

        $service = new TeaserService($client);
        $result = $service->generate('Cycling', 'body');

        self::assertSame(
            'A friendly guide to your first 100 km on two wheels. Includes gear, route planning, and rain prep.',
            $result,
        );
    }

    public function testEnforcesMaxLengthCap(): void
    {
        $longTeaser = str_repeat('Lorem ipsum dolor sit amet, consectetur adipiscing elit. ', 10);
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['teaser' => $longTeaser]);

        $service = new TeaserService($client);
        $result = $service->generate('Title', 'body');

        self::assertLessThanOrEqual(TeaserService::MAX_LENGTH, mb_strlen($result));
        self::assertNotEmpty($result);
    }

    public function testTrimsBackToLastSentenceEndWhenLongerThanCap(): void
    {
        $teaser = 'First sentence here. Second sentence is a bit longer to push us past the cap, '
            . 'and we keep adding words and adding words and adding words and adding words until '
            . 'we definitely exceed two hundred and forty characters because the test needs that, here we go more text more.';
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['teaser' => $teaser]);

        $service = new TeaserService($client);
        $result = $service->generate('Title', 'body');

        self::assertLessThanOrEqual(TeaserService::MAX_LENGTH, mb_strlen($result));
        // Must end on a sentence-ending punctuation.
        self::assertMatchesRegularExpression('/[.!?…]$/u', $result);
    }

    public function testAppendsPeriodWhenNoSentenceEndExists(): void
    {
        // LLM emitted a no-punctuation snippet at the cap.
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'teaser' => str_repeat('a-word-that-keeps-going ', 12),
        ]);

        $service = new TeaserService($client);
        $result = $service->generate('Title', 'body');

        self::assertLessThanOrEqual(TeaserService::MAX_LENGTH, mb_strlen($result));
        self::assertStringEndsWith('.', $result);
    }

    public function testStripsWrappingQuotes(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['teaser' => '"Quoted teaser content here."']);

        $service = new TeaserService($client);
        $result = $service->generate('Title', 'body');

        self::assertSame('Quoted teaser content here.', $result);
    }

    public function testStripsHtmlAndCollapsesWhitespaceInBody(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(
                self::callback(function (array $messages): bool {
                    self::assertSame('system', $messages[0]['role']);
                    self::assertStringContainsString('editorial copywriter', $messages[0]['content']);

                    $userMsg = $messages[1]['content'];
                    self::assertStringNotContainsString('<', $userMsg);
                    self::assertStringContainsString('Hello world.', $userMsg);
                    self::assertDoesNotMatchRegularExpression('/  +/', $userMsg);
                    return true;
                }),
                self::equalTo('AiEditorialTeaserResult'),
                self::callback(function (array $schema): bool {
                    self::assertSame('object', $schema['type']);
                    self::assertContains('teaser', $schema['required']);
                    self::assertSame(235, $schema['properties']['teaser']['maxLength']);
                    self::assertFalse($schema['additionalProperties']);
                    return true;
                }),
                self::anything(),
            )
            ->willReturn(['teaser' => 'A short teaser.']);

        $service = new TeaserService($client);
        $service->generate('Title', "<p>Hello   world.</p>\n<strong>More</strong>");
    }

    public function testThrowsOnEmptyTitleAndEmptyBody(): void
    {
        $service = new TeaserService($this->createMock(LmStudioClient::class));

        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_INVALID_RESPONSE);
        $service->generate('   ', '<p>   </p>');
    }

    public function testThrowsWhenLlmReturnsEmptyTeaser(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['teaser' => '   ']);

        $service = new TeaserService($client);
        $this->expectException(LmStudioException::class);
        $service->generate('Title', 'body');
    }

    public function testPropagatesUnreachableException(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willThrowException(
            new LmStudioException('refused', LmStudioException::CODE_UNREACHABLE),
        );

        $service = new TeaserService($client);
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_UNREACHABLE);
        $service->generate('Title', 'Body content here.');
    }

    public function testGermanContentEndsOnGermanPunctuation(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'teaser' => 'Eine Berliner Digitalagentur mit Fokus auf TYPO3 und Barrierefreiheit. Seit 2012 im DACH-Raum aktiv.',
        ]);

        $service = new TeaserService($client);
        $result = $service->generate('Über uns', 'Geschäftsbericht 2025.');

        self::assertStringEndsWith('.', $result);
        self::assertStringContainsString('Berliner Digitalagentur', $result);
    }

    public function testEllipsisCountsAsSentenceEnd(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'teaser' => 'A pondering thought…',
        ]);

        $service = new TeaserService($client);
        $result = $service->generate('Title', 'body');

        self::assertStringEndsWith('…', $result);
    }

    public function testRetainsExclamationAndQuestionMarks(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'teaser' => 'What is TYPO3 v13? A modern enterprise CMS!',
        ]);

        $service = new TeaserService($client);
        $result = $service->generate('Title', 'body');

        self::assertStringEndsWith('!', $result);
    }
}
