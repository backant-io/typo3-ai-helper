<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service\Quality;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\LmStudioClient;
use Kairos\AiEditorialHelper\Service\Quality\LlmQualityChecker;
use PHPUnit\Framework\TestCase;

final class LlmQualityCheckerTest extends TestCase
{
    public function testReturnsEmptyForEmptyContent(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::never())->method('chatJsonSchema');

        $checker = new LlmQualityChecker($client);
        self::assertSame([], $checker->check('   ', '   '));
    }

    public function testReturnsNormalizedFlags(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'issues' => [
                ['kind' => 'tone', 'severity' => 'warning', 'message' => 'Mixing Sie and du.', 'location' => 'Liebe Kunden'],
                ['kind' => 'clarity', 'severity' => 'info', 'message' => 'Paragraph 2 unclear.'],
            ],
        ]);

        $checker = new LlmQualityChecker($client);
        $flags = $checker->check('Title', '<p>body</p>');

        self::assertCount(2, $flags);
        self::assertSame('tone', $flags[0]['kind']);
        self::assertSame('warning', $flags[0]['severity']);
        self::assertSame('Liebe Kunden', $flags[0]['location']);
        self::assertSame('clarity', $flags[1]['kind']);
        self::assertArrayNotHasKey('location', $flags[1]);
    }

    public function testFiltersOutInvalidKinds(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'issues' => [
                ['kind' => 'tone', 'severity' => 'warning', 'message' => 'fine'],
                ['kind' => 'invented', 'severity' => 'warning', 'message' => 'should be dropped'],
                ['kind' => 'sentence_length', 'severity' => 'warning', 'message' => 'rule-based, not allowed here'],
            ],
        ]);

        $checker = new LlmQualityChecker($client);
        $flags = $checker->check('Title', 'body');

        self::assertCount(1, $flags);
        self::assertSame('tone', $flags[0]['kind']);
    }

    public function testFiltersOutInvalidSeverities(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'issues' => [
                ['kind' => 'tone', 'severity' => 'critical', 'message' => 'invalid severity'],
                ['kind' => 'tone', 'severity' => 'error', 'message' => 'error not allowed for LLM checks'],
                ['kind' => 'clarity', 'severity' => 'info', 'message' => 'fine'],
            ],
        ]);

        $checker = new LlmQualityChecker($client);
        $flags = $checker->check('Title', 'body');

        self::assertCount(1, $flags);
        self::assertSame('clarity', $flags[0]['kind']);
    }

    public function testCapsAtFiveFlags(): void
    {
        $issues = [];
        for ($i = 0; $i < 10; $i++) {
            $issues[] = ['kind' => 'clarity', 'severity' => 'info', 'message' => "issue $i"];
        }
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['issues' => $issues]);

        $checker = new LlmQualityChecker($client);
        $flags = $checker->check('Title', 'body');

        self::assertCount(5, $flags);
    }

    public function testTruncatesOverlongMessage(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'issues' => [
                ['kind' => 'tone', 'severity' => 'warning', 'message' => str_repeat('a', 500)],
            ],
        ]);

        $checker = new LlmQualityChecker($client);
        $flags = $checker->check('Title', 'body');

        self::assertCount(1, $flags);
        self::assertSame(200, mb_strlen($flags[0]['message']));
    }

    public function testReturnsEmptyOnGarbageShape(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn(['issues' => 'not-an-array']);

        $checker = new LlmQualityChecker($client);
        self::assertSame([], $checker->check('Title', 'body'));
    }

    public function testPropagatesUnreachable(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willThrowException(
            new LmStudioException('refused', LmStudioException::CODE_UNREACHABLE),
        );

        $checker = new LlmQualityChecker($client);
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_UNREACHABLE);
        $checker->check('Title', 'body');
    }

    public function testIncludesEnumConstraintInSchema(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(
                self::anything(),
                self::equalTo('AiEditorialQualityResult'),
                self::callback(function (array $schema): bool {
                    $items = $schema['properties']['issues']['items'];
                    self::assertSame(['tone', 'clarity'], $items['properties']['kind']['enum']);
                    self::assertSame(['info', 'warning'], $items['properties']['severity']['enum']);
                    self::assertSame(5, $schema['properties']['issues']['maxItems']);
                    return true;
                }),
                self::anything(),
            )
            ->willReturn(['issues' => []]);

        $checker = new LlmQualityChecker($client);
        $checker->check('Title', '<p>body</p>');
    }
}
