<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service\Quality;

use Kairos\AiEditorialHelper\Service\Quality\RuleBasedQualityChecker;
use PHPUnit\Framework\TestCase;

final class RuleBasedQualityCheckerTest extends TestCase
{
    public function testReturnsEmptyForEmptyHtml(): void
    {
        $checker = new RuleBasedQualityChecker();
        self::assertSame([], $checker->check(''));
        self::assertSame([], $checker->check('   '));
    }

    public function testFlagsLongSentenceAsWarning(): void
    {
        $longSentence = 'This sentence has way too many words and keeps going '
            . 'on and on and on until the editor really should split it into '
            . 'smaller pieces because nobody likes reading run-on sentences anymore.';

        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkSentenceLengths($longSentence);

        self::assertCount(1, $flags);
        self::assertSame('sentence_length', $flags[0]['kind']);
        self::assertSame('warning', $flags[0]['severity']);
        self::assertStringContainsString('words', $flags[0]['message']);
    }

    public function testDoesNotFlagShortSentence(): void
    {
        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkSentenceLengths('A short sentence. Another short one.');
        self::assertSame([], $flags);
    }

    public function testFlagsMultipleH1AsError(): void
    {
        $html = '<h1>First</h1><p>text</p><h1>Second</h1>';
        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkHeadingStructure($html);

        $errors = array_filter($flags, static fn (array $f): bool => $f['severity'] === 'error');
        self::assertNotEmpty($errors, 'Should report multiple h1 as an error');
        $first = array_values($errors)[0];
        self::assertSame('heading_structure', $first['kind']);
        self::assertStringContainsString('2', $first['message']);
    }

    public function testFlagsMissingH1AsError(): void
    {
        $html = '<h2>Only H2</h2><p>body</p>';
        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkHeadingStructure($html);

        $errors = array_filter($flags, static fn (array $f): bool => $f['severity'] === 'error');
        self::assertNotEmpty($errors);
        self::assertStringContainsString('h1', strtolower(array_values($errors)[0]['message']));
    }

    public function testFlagsHeadingHierarchySkip(): void
    {
        // h2 → h4 (skipping h3) is a warning
        $html = '<h1>Title</h1><h2>Section</h2><h4>Sub-sub</h4>';
        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkHeadingStructure($html);

        $skipFlags = array_filter(
            $flags,
            static fn (array $f): bool => str_contains(strtolower($f['message']), 'skip'),
        );
        self::assertCount(1, $skipFlags);
        self::assertSame('warning', array_values($skipFlags)[0]['severity']);
    }

    public function testAllowsValidH1H2H3Sequence(): void
    {
        $html = '<h1>A</h1><h2>B</h2><h3>C</h3><h2>D</h2><h3>E</h3>';
        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkHeadingStructure($html);

        $errors = array_filter($flags, static fn (array $f): bool => $f['severity'] === 'error');
        $skipFlags = array_filter(
            $flags,
            static fn (array $f): bool => str_contains(strtolower($f['message']), 'skip'),
        );
        self::assertSame([], array_values($errors));
        self::assertSame([], array_values($skipFlags));
    }

    public function testCombinedCheckReturnsAllFlags(): void
    {
        $html = '<h2>Section without H1</h2><p>'
            . str_repeat('word ', 35) . 'This is a long opening sentence that definitely runs past the cap.</p>'
            . '<h4>Skipping ahead</h4>';

        $checker = new RuleBasedQualityChecker();
        $flags = $checker->check($html);

        // Expect at least: missing h1, heading skip, sentence length
        $kinds = array_map(static fn (array $f): string => $f['kind'], $flags);
        self::assertContains('heading_structure', $kinds);
        self::assertContains('sentence_length', $kinds);
    }

    public function testHandlesGermanContent(): void
    {
        $sentence = 'Das ist ein sehr langer Satz der einfach nicht aufhören will und immer '
            . 'weiter und weiter geht ohne irgendwann mal an einer vernünftigen Stelle zu '
            . 'enden was die Lesbarkeit deutlich verschlechtert.';

        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkSentenceLengths($sentence);
        self::assertCount(1, $flags);
        self::assertSame('warning', $flags[0]['severity']);
    }

    public function testStripsHtmlBeforeCountingSentences(): void
    {
        // Embedded markup must not inflate word counts.
        $html = '<p>Short.</p><p>Also <strong>short</strong>.</p>';
        $checker = new RuleBasedQualityChecker();
        $flags = $checker->checkSentenceLengths($html);
        self::assertSame([], $flags);
    }

    public function testRunsUnderFiftyMillisecondsOnTypicalPage(): void
    {
        // Build a typical-sized page: ~5000 chars of body + 5 headings.
        $body = '<h1>Title</h1>';
        for ($i = 1; $i <= 4; $i++) {
            $body .= sprintf('<h2>Section %d</h2>', $i);
            $body .= '<p>' . str_repeat('Lorem ipsum dolor sit amet. ', 30) . '</p>';
        }

        $checker = new RuleBasedQualityChecker();
        $start = microtime(true);
        $checker->check($body);
        $elapsedMs = (microtime(true) - $start) * 1000;

        self::assertLessThan(50.0, $elapsedMs, sprintf('Rule-based check took %.1fms (cap 50ms)', $elapsedMs));
    }
}
