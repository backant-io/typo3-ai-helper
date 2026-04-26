<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\LmStudioClient;
use Kairos\AiEditorialHelper\Service\TranslationStubService;
use PHPUnit\Framework\TestCase;

final class TranslationStubServiceTest extends TestCase
{
    public function testRejectsUnsupportedSourceLanguage(): void
    {
        $service = new TranslationStubService($this->createMock(LmStudioClient::class));
        $this->expectException(LmStudioException::class);
        $service->translate('<p>foo</p>', 'fr', 'en');
    }

    public function testRejectsUnsupportedTargetLanguage(): void
    {
        $service = new TranslationStubService($this->createMock(LmStudioClient::class));
        $this->expectException(LmStudioException::class);
        $service->translate('<p>foo</p>', 'de', 'fr');
    }

    public function testReturnsStubMarkerWhenSourceEqualsTarget(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::never())->method('chatJsonSchema');

        $service = new TranslationStubService($client);
        $result = $service->translate('<p>Hello.</p>', 'en', 'en');

        self::assertStringContainsString(TranslationStubService::STUB_PREFIX, $result);
        self::assertStringContainsString('<p>Hello.</p>', $result);
    }

    public function testRejectsEmptyContent(): void
    {
        $service = new TranslationStubService($this->createMock(LmStudioClient::class));
        $this->expectException(LmStudioException::class);
        $service->translate('   ', 'de', 'en');
    }

    public function testTranslatesSimpleParagraphPreservingTag(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->willReturn([
                'translations' => [
                    ['index' => 0, 'text' => 'Hello world.'],
                ],
            ]);

        $service = new TranslationStubService($client);
        $result = $service->translate('<p>Hallo Welt.</p>', 'de', 'en');

        self::assertStringContainsString(TranslationStubService::STUB_PREFIX, $result);
        self::assertStringContainsString('<p>Hello world.</p>', $result);
        self::assertStringNotContainsString('Hallo Welt.', $result);
    }

    public function testPreservesAttributesOnLinks(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'translations' => [
                ['index' => 0, 'text' => 'Visit '],
                ['index' => 1, 'text' => 'our site'],
                ['index' => 2, 'text' => '.'],
            ],
        ]);

        $service = new TranslationStubService($client);
        $result = $service->translate(
            '<p>Besuche <a href="https://example.com" class="cta">unsere Seite</a>.</p>',
            'de',
            'en',
        );

        self::assertStringContainsString('href="https://example.com"', $result);
        self::assertStringContainsString('class="cta"', $result);
        self::assertStringContainsString('our site', $result);
    }

    public function testPreservesNestedStructure(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'translations' => [
                ['index' => 0, 'text' => 'About us'],
                ['index' => 1, 'text' => 'We are a digital agency.'],
                ['index' => 2, 'text' => 'TYPO3'],
                ['index' => 3, 'text' => ' and accessibility.'],
            ],
        ]);

        $service = new TranslationStubService($client);
        $result = $service->translate(
            '<h1>Über uns</h1><p>Wir sind eine Digitalagentur.</p><ul><li><strong>TYPO3</strong> und Barrierefreiheit.</li></ul>',
            'de',
            'en',
        );

        self::assertStringContainsString('<h1>About us</h1>', $result);
        self::assertStringContainsString('We are a digital agency.', $result);
        self::assertStringContainsString('<strong>TYPO3</strong>', $result);
        self::assertStringContainsString('accessibility.', $result);
        self::assertStringContainsString('<ul>', $result);
        self::assertStringContainsString('<li>', $result);
    }

    public function testSkipsScriptAndStyleContents(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(self::callback(function (array $messages): bool {
                $userJson = $messages[1]['content'];
                self::assertStringContainsString('Hallo', $userJson);
                // script/style contents must not appear in the LLM input
                self::assertStringNotContainsString('alert', $userJson);
                self::assertStringNotContainsString('background', $userJson);
                return true;
            }))
            ->willReturn(['translations' => [['index' => 0, 'text' => 'Hello']]]);

        $service = new TranslationStubService($client);
        $service->translate(
            '<p>Hallo</p><script>alert("Hallo");</script><style>body { background: red; }</style>',
            'de',
            'en',
        );
    }

    public function testHandlesPureMarkupWithNoText(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::never())->method('chatJsonSchema');

        $service = new TranslationStubService($client);
        $result = $service->translate('<hr><br><img src="x.jpg" alt="">', 'de', 'en');

        self::assertStringContainsString(TranslationStubService::STUB_PREFIX, $result);
    }

    public function testPropagatesUnreachableException(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willThrowException(
            new LmStudioException('refused', LmStudioException::CODE_UNREACHABLE),
        );

        $service = new TranslationStubService($client);
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_UNREACHABLE);
        $service->translate('<p>foo</p>', 'de', 'en');
    }

    public function testIncludesLanguagePairInPrompt(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(self::callback(function (array $messages): bool {
                $sys = $messages[0]['content'];
                self::assertStringContainsString('German', $sys);
                self::assertStringContainsString('English', $sys);
                return true;
            }))
            ->willReturn(['translations' => [['index' => 0, 'text' => 'Hello']]]);

        $service = new TranslationStubService($client);
        $service->translate('<p>Hallo</p>', 'de', 'en');
    }

    public function testSchemaPinsArrayLength(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::once())
            ->method('chatJsonSchema')
            ->with(
                self::anything(),
                self::equalTo('AiEditorialTranslationResult'),
                self::callback(function (array $schema): bool {
                    $items = $schema['properties']['translations'];
                    self::assertSame(2, $items['minItems']);
                    self::assertSame(2, $items['maxItems']);
                    self::assertSame('integer', $items['items']['properties']['index']['type']);
                    self::assertSame('string', $items['items']['properties']['text']['type']);
                    return true;
                }),
                self::anything(),
            )
            ->willReturn(['translations' => [
                ['index' => 0, 'text' => 'Hello'],
                ['index' => 1, 'text' => 'world'],
            ]]);

        $service = new TranslationStubService($client);
        $service->translate('<p>Hallo</p><p>Welt</p>', 'de', 'en');
    }

    public function testRejectsContentWithTooManyTextNodes(): void
    {
        // Build content with > 80 text nodes — exceeds MAX_NODES_PER_BATCH.
        $nodes = [];
        for ($i = 0; $i < 100; $i++) {
            $nodes[] = "<p>Absatz $i</p>";
        }
        $client = $this->createMock(LmStudioClient::class);
        $client->expects(self::never())->method('chatJsonSchema');

        $service = new TranslationStubService($client);
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_INVALID_RESPONSE);
        $service->translate(implode('', $nodes), 'de', 'en');
    }

    public function testHandlesTextWithUnicodeUmlauts(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'translations' => [
                ['index' => 0, 'text' => 'About us'],
            ],
        ]);

        $service = new TranslationStubService($client);
        $result = $service->translate('<p>Über uns – Geschäftsbericht</p>', 'de', 'en');

        self::assertStringContainsString('About us', $result);
    }

    public function testIgnoresMalformedTranslationEntriesGracefully(): void
    {
        $client = $this->createMock(LmStudioClient::class);
        $client->method('chatJsonSchema')->willReturn([
            'translations' => [
                ['index' => 0, 'text' => 'Hello'],
                ['index' => 99, 'text' => 'orphan — unused index'],
                'not-an-object',
                ['only-text-no-index' => 'foo'],
            ],
        ]);

        $service = new TranslationStubService($client);
        $result = $service->translate('<p>Hallo</p>', 'de', 'en');

        self::assertStringContainsString('Hello', $result);
        self::assertStringNotContainsString('orphan', $result);
    }
}
