<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Strongly-typed accessor for ai_editorial_helper ext_conf_template settings.
 *
 * Reads once from ExtensionConfiguration and exposes typed getters.
 * Falls back to documented defaults on any read failure so the extension never
 * blows up on a missing/empty install setting.
 */
class ExtensionSettings implements SingletonInterface
{
    public const EXT_KEY = 'ai_editorial_helper';

    public const DEFAULT_ENDPOINT = 'http://localhost:1234/v1';
    public const DEFAULT_MODEL = 'qwen/qwen3-14b';
    public const DEFAULT_TIMEOUT = 60;

    private string $endpoint;
    private string $model;
    private int $timeout;
    private string $apiKey;

    public function __construct(ExtensionConfiguration $configuration)
    {
        $raw = [];
        try {
            $raw = (array)$configuration->get(self::EXT_KEY);
        } catch (\Throwable) {
            // Fall through to defaults — extension may not be fully installed yet.
        }

        $this->endpoint = self::trimOrDefault($raw['endpoint'] ?? null, self::DEFAULT_ENDPOINT);
        $this->model = self::trimOrDefault($raw['model'] ?? null, self::DEFAULT_MODEL);
        $this->timeout = self::positiveIntOrDefault($raw['timeout'] ?? null, self::DEFAULT_TIMEOUT);
        $this->apiKey = trim((string)($raw['apiKey'] ?? ''));
    }

    public function getEndpoint(): string
    {
        return rtrim($this->endpoint, '/');
    }

    public function getModel(): string
    {
        return $this->model;
    }

    public function getTimeout(): int
    {
        return $this->timeout;
    }

    public function getApiKey(): string
    {
        return $this->apiKey;
    }

    public function hasApiKey(): bool
    {
        return $this->apiKey !== '';
    }

    private static function trimOrDefault(mixed $value, string $default): string
    {
        $candidate = trim((string)($value ?? ''));
        return $candidate !== '' ? $candidate : $default;
    }

    private static function positiveIntOrDefault(mixed $value, int $default): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $int = (int)$value;
        return $int > 0 ? $int : $default;
    }
}
