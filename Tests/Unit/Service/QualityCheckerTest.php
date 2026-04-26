<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\QualityChecker;
use Kairos\AiEditorialHelper\Service\Quality\LlmQualityChecker;
use Kairos\AiEditorialHelper\Service\Quality\RuleBasedQualityChecker;
use PHPUnit\Framework\TestCase;

final class QualityCheckerTest extends TestCase
{
    public function testCombinesRuleBasedAndLlmFlags(): void
    {
        $rule = $this->createMock(RuleBasedQualityChecker::class);
        $rule->method('check')->willReturn([
            ['kind' => 'sentence_length', 'severity' => 'warning', 'message' => 'long sentence'],
        ]);

        $llm = $this->createMock(LlmQualityChecker::class);
        $llm->method('check')->willReturn([
            ['kind' => 'clarity', 'severity' => 'info', 'message' => 'unclear paragraph'],
        ]);

        $checker = new QualityChecker($rule, $llm);
        $flags = $checker->check('Title', '<p>body</p>');

        self::assertCount(2, $flags);
        // warning sorts before info
        self::assertSame('warning', $flags[0]['severity']);
        self::assertSame('info', $flags[1]['severity']);
    }

    public function testSkipsLlmWhenIncludeLlmFalse(): void
    {
        $rule = $this->createMock(RuleBasedQualityChecker::class);
        $rule->method('check')->willReturn([]);

        $llm = $this->createMock(LlmQualityChecker::class);
        $llm->expects(self::never())->method('check');

        $checker = new QualityChecker($rule, $llm);
        $flags = $checker->check('Title', '<p>body</p>', false);
        self::assertSame([], $flags);
    }

    public function testRecoversWhenLlmThrows(): void
    {
        $rule = $this->createMock(RuleBasedQualityChecker::class);
        $rule->method('check')->willReturn([
            ['kind' => 'heading_structure', 'severity' => 'error', 'message' => 'no h1'],
        ]);

        $llm = $this->createMock(LlmQualityChecker::class);
        $llm->method('check')->willThrowException(
            new LmStudioException('refused', LmStudioException::CODE_UNREACHABLE),
        );

        $checker = new QualityChecker($rule, $llm);
        $flags = $checker->check('Title', '<p>body</p>');

        self::assertGreaterThanOrEqual(2, count($flags));
        $kinds = array_map(static fn (array $f): string => $f['kind'], $flags);
        self::assertContains('heading_structure', $kinds);
        self::assertContains('llm_unavailable', $kinds);
    }

    public function testSortsByErrorWarningInfoOrder(): void
    {
        $rule = $this->createMock(RuleBasedQualityChecker::class);
        $rule->method('check')->willReturn([
            ['kind' => 'sentence_length', 'severity' => 'info', 'message' => 'note'],
            ['kind' => 'sentence_length', 'severity' => 'warning', 'message' => 'long sentence'],
            ['kind' => 'heading_structure', 'severity' => 'error', 'message' => 'no h1'],
            ['kind' => 'heading_structure', 'severity' => 'warning', 'message' => 'skipped level'],
        ]);

        $llm = $this->createMock(LlmQualityChecker::class);
        $llm->method('check')->willReturn([]);

        $checker = new QualityChecker($rule, $llm);
        $flags = $checker->check('Title', 'body');

        $severities = array_map(static fn (array $f): string => $f['severity'], $flags);
        self::assertSame(['error', 'warning', 'warning', 'info'], $severities);
    }

    public function testAppendsSentinelFlagOnLlmOutage(): void
    {
        $rule = $this->createMock(RuleBasedQualityChecker::class);
        $rule->method('check')->willReturn([]);

        $llm = $this->createMock(LlmQualityChecker::class);
        $llm->method('check')->willThrowException(
            new LmStudioException('No model loaded', LmStudioException::CODE_NO_MODEL_LOADED),
        );

        $checker = new QualityChecker($rule, $llm);
        $flags = $checker->check('Title', 'body');

        self::assertCount(1, $flags);
        self::assertSame('llm_unavailable', $flags[0]['kind']);
        self::assertSame('info', $flags[0]['severity']);
        self::assertStringContainsString('No model loaded', $flags[0]['message']);
    }
}
