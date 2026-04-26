<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Kairos\AiEditorialHelper\Service\ExtensionSettings;
use Kairos\AiEditorialHelper\Service\LmStudioClient;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class LmStudioClientTest extends TestCase
{
    public function testChatReturnsAssistantContent(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                'http://localhost:1234/v1/chat/completions',
                'POST',
                self::callback(function (array $options): bool {
                    self::assertArrayHasKey('json', $options);
                    self::assertSame('qwen/qwen3-14b', $options['json']['model']);
                    self::assertSame([['role' => 'user', 'content' => 'hi']], $options['json']['messages']);
                    self::assertSame(60, $options['timeout']);
                    self::assertSame('application/json', $options['headers']['Content-Type']);
                    self::assertArrayNotHasKey('Authorization', $options['headers']);
                    return true;
                }),
            )
            ->willReturn($this->jsonResponse(200, [
                'choices' => [['message' => ['content' => "  Hello there.  "]]],
            ]));

        $client = new LmStudioClient($factory, $this->settings());
        $result = $client->chat([['role' => 'user', 'content' => 'hi']]);

        self::assertSame('Hello there.', $result);
    }

    public function testChatJsonSchemaWrapsRequestWithSchemaResponseFormat(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                'POST',
                self::callback(function (array $options): bool {
                    $rf = $options['json']['response_format'] ?? null;
                    self::assertSame('json_schema', $rf['type'] ?? null);
                    self::assertSame('Demo', $rf['json_schema']['name'] ?? null);
                    self::assertTrue($rf['json_schema']['strict'] ?? false);
                    self::assertSame(
                        ['type' => 'object', 'properties' => ['foo' => ['type' => 'integer']]],
                        $rf['json_schema']['schema'] ?? null,
                    );
                    return true;
                }),
            )
            ->willReturn($this->jsonResponse(200, [
                'choices' => [['message' => ['content' => '{"foo": 1, "bar": "baz"}']]],
            ]));

        $client = new LmStudioClient($factory, $this->settings());
        $result = $client->chatJsonSchema(
            [['role' => 'user', 'content' => 'p']],
            'Demo',
            ['type' => 'object', 'properties' => ['foo' => ['type' => 'integer']]],
        );

        self::assertSame(['foo' => 1, 'bar' => 'baz'], $result);
    }

    public function testChatJsonSchemaThrowsOnNonJsonContent(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->jsonResponse(200, [
            'choices' => [['message' => ['content' => 'I am sorry, no JSON for you.']]],
        ]));

        $client = new LmStudioClient($factory, $this->settings());
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_INVALID_RESPONSE);
        $client->chatJsonSchema(
            [['role' => 'user', 'content' => 'p']],
            'Demo',
            ['type' => 'object'],
        );
    }

    public function testIncludesAuthorizationHeaderWhenApiKeyConfigured(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->expects(self::once())
            ->method('request')
            ->with(
                self::anything(),
                self::anything(),
                self::callback(function (array $options): bool {
                    self::assertSame('Bearer s3cr3t', $options['headers']['Authorization']);
                    return true;
                }),
            )
            ->willReturn($this->jsonResponse(200, [
                'choices' => [['message' => ['content' => 'ok']]],
            ]));

        $client = new LmStudioClient($factory, $this->settings(apiKey: 's3cr3t'));
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testThrowsUnreachableWhenRequestFactoryThrows(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willThrowException(new \RuntimeException('ECONNREFUSED'));

        $client = new LmStudioClient($factory, $this->settings());
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_UNREACHABLE);
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testClassifiesNoModelLoadedFromOpenAiErrorShape(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->jsonResponse(400, [
            'error' => ['message' => 'No model loaded — load a model in LM Studio first.'],
        ]));

        $client = new LmStudioClient($factory, $this->settings());
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_NO_MODEL_LOADED);
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testClassifies404AsNoModelLoaded(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->jsonResponse(404, [
            'error' => 'unknown route',
        ]));

        $client = new LmStudioClient($factory, $this->settings());
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_NO_MODEL_LOADED);
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testHttpErrorCodeForGenericFailure(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->jsonResponse(500, [
            'error' => ['message' => 'internal server error'],
        ]));

        $client = new LmStudioClient($factory, $this->settings());
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_HTTP_ERROR);
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testThrowsInvalidResponseWhenBodyIsNotJson(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->rawResponse(200, '<html>not json</html>'));

        $client = new LmStudioClient($factory, $this->settings());
        $this->expectException(LmStudioException::class);
        $this->expectExceptionCode(LmStudioException::CODE_INVALID_RESPONSE);
        $client->chat([['role' => 'user', 'content' => 'hi']]);
    }

    public function testIsAvailableFalseOnConnectionError(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willThrowException(new \RuntimeException('refused'));

        $client = new LmStudioClient($factory, $this->settings());
        self::assertFalse($client->isAvailable());
    }

    public function testIsAvailableFalseOnEmptyModelList(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->jsonResponse(200, ['data' => []]));

        $client = new LmStudioClient($factory, $this->settings());
        self::assertFalse($client->isAvailable());
    }

    public function testIsAvailableTrueWhenModelsListed(): void
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')->willReturn($this->jsonResponse(200, [
            'data' => [['id' => 'qwen/qwen3-14b']],
        ]));

        $client = new LmStudioClient($factory, $this->settings());
        self::assertTrue($client->isAvailable());
        self::assertSame(['qwen/qwen3-14b'], $client->listModels());
    }

    private function settings(
        string $endpoint = 'http://localhost:1234/v1',
        string $model = 'qwen/qwen3-14b',
        int $timeout = 60,
        string $apiKey = '',
    ): ExtensionSettings {
        $settings = $this->createMock(ExtensionSettings::class);
        $settings->method('getEndpoint')->willReturn(rtrim($endpoint, '/'));
        $settings->method('getModel')->willReturn($model);
        $settings->method('getTimeout')->willReturn($timeout);
        $settings->method('getApiKey')->willReturn($apiKey);
        $settings->method('hasApiKey')->willReturn($apiKey !== '');
        return $settings;
    }

    private function jsonResponse(int $status, array $body): ResponseInterface
    {
        return $this->rawResponse($status, json_encode($body, JSON_THROW_ON_ERROR));
    }

    private function rawResponse(int $status, string $body): ResponseInterface
    {
        $stream = $this->createMock(StreamInterface::class);
        $stream->method('__toString')->willReturn($body);

        $response = $this->createMock(ResponseInterface::class);
        $response->method('getStatusCode')->willReturn($status);
        $response->method('getBody')->willReturn($stream);
        return $response;
    }
}
