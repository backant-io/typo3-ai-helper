<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use Kairos\AiEditorialHelper\Exception\LmStudioException;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

/**
 * Thin HTTP client for LM Studio's OpenAI-compatible API.
 *
 * Shared by all editorial helper services (#1-#6). Speaks `/v1/chat/completions`
 * and `/v1/models`. No streaming, no function-calling — kept deliberately small.
 *
 * All errors surface as {@see LmStudioException} with a typed code so callers
 * can render a human-friendly backend message.
 */
class LmStudioClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ExtensionSettings $settings,
    ) {
    }

    /**
     * Send a chat completion request and return the assistant's text content.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param array<string, mixed> $options Extra fields merged into the request body
     *                                      (e.g. ['temperature' => 0.2, 'max_tokens' => 256]).
     */
    public function chat(array $messages, array $options = []): string
    {
        $body = array_merge(
            ['model' => $this->settings->getModel(), 'messages' => $messages],
            $options,
        );

        $response = $this->postJson('/chat/completions', $body);
        $payload = $this->decodeJson($response);

        $content = $payload['choices'][0]['message']['content'] ?? null;
        if (!is_string($content)) {
            throw new LmStudioException(
                'LM Studio response missing choices[0].message.content',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }
        return trim($content);
    }

    /**
     * Send a chat completion that must conform to a JSON Schema.
     *
     * LM Studio uses `response_format: { type: "json_schema", json_schema: {...} }`
     * (NOT OpenAI's older `json_object` shorthand — that returns a 400 error on
     * LM Studio). Grammar-constrained sampling enforces the schema during
     * generation, so the returned string is guaranteed to parse.
     *
     * @param array<int, array{role: string, content: string}> $messages
     * @param string $schemaName  Identifier passed to LM Studio (alphanumeric/underscore).
     * @param array<string, mixed> $schema       JSON Schema describing the expected object.
     * @param array<string, mixed> $options      Extra fields merged into the request body.
     * @return array<mixed>
     */
    public function chatJsonSchema(array $messages, string $schemaName, array $schema, array $options = []): array
    {
        $merged = $options;
        $merged['response_format'] = [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => $schemaName,
                'strict' => true,
                'schema' => $schema,
            ],
        ];
        $raw = $this->chat($messages, $merged);
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LmStudioException(
                'LM Studio returned non-JSON content despite a json_schema response_format',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }
        return $decoded;
    }

    /**
     * Check whether the configured endpoint responds and at least one model is loaded.
     * Returns false on any failure (connection refused, timeout, empty model list).
     */
    public function isAvailable(): bool
    {
        try {
            $response = $this->getJson('/models');
            $payload = $this->decodeJson($response);
            $models = $payload['data'] ?? [];
            return is_array($models) && $models !== [];
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    public function listModels(): array
    {
        $response = $this->getJson('/models');
        $payload = $this->decodeJson($response);
        $models = $payload['data'] ?? [];
        if (!is_array($models)) {
            return [];
        }
        $ids = [];
        foreach ($models as $model) {
            if (is_array($model) && isset($model['id']) && is_string($model['id'])) {
                $ids[] = $model['id'];
            }
        }
        return $ids;
    }

    private function postJson(string $path, array $body): ResponseInterface
    {
        return $this->request('POST', $path, [
            'json' => $body,
            'headers' => $this->buildHeaders(),
            'timeout' => $this->settings->getTimeout(),
            'http_errors' => false,
        ]);
    }

    private function getJson(string $path): ResponseInterface
    {
        return $this->request('GET', $path, [
            'headers' => $this->buildHeaders(),
            'timeout' => $this->settings->getTimeout(),
            'http_errors' => false,
        ]);
    }

    private function request(string $method, string $path, array $options): ResponseInterface
    {
        $uri = $this->settings->getEndpoint() . '/' . ltrim($path, '/');
        try {
            return $this->requestFactory->request($uri, $method, $options);
        } catch (\Throwable $e) {
            throw new LmStudioException(
                sprintf('Cannot reach LM Studio at %s: %s', $uri, $e->getMessage()),
                LmStudioException::CODE_UNREACHABLE,
                $e,
            );
        }
    }

    /**
     * @return array<string, string>
     */
    private function buildHeaders(): array
    {
        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
        if ($this->settings->hasApiKey()) {
            $headers['Authorization'] = 'Bearer ' . $this->settings->getApiKey();
        }
        return $headers;
    }

    /**
     * @return array<mixed>
     */
    private function decodeJson(ResponseInterface $response): array
    {
        $status = $response->getStatusCode();
        $raw = (string)$response->getBody();

        if ($status >= 400) {
            $message = $this->extractErrorMessage($raw) ?? sprintf('HTTP %d from LM Studio', $status);
            $code = $this->classifyError($status, $message);
            throw new LmStudioException($message, $code);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw new LmStudioException(
                'LM Studio returned a non-JSON response body',
                LmStudioException::CODE_INVALID_RESPONSE,
            );
        }
        return $decoded;
    }

    private function extractErrorMessage(string $raw): ?string
    {
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        // OpenAI shape: {"error": {"message": "..."}}; LM Studio also returns plain {"error": "..."}
        $err = $decoded['error'] ?? null;
        if (is_array($err) && isset($err['message']) && is_string($err['message'])) {
            return $err['message'];
        }
        if (is_string($err)) {
            return $err;
        }
        return null;
    }

    private function classifyError(int $status, string $message): int
    {
        $lower = strtolower($message);
        if (
            $status === 404
            || str_contains($lower, 'no model')
            || str_contains($lower, 'model not found')
            || str_contains($lower, 'no models loaded')
        ) {
            return LmStudioException::CODE_NO_MODEL_LOADED;
        }
        return LmStudioException::CODE_HTTP_ERROR;
    }
}
