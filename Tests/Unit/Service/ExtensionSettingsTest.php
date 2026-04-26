<?php

declare(strict_types=1);

namespace Kairos\AiEditorialHelper\Tests\Unit\Service;

use Kairos\AiEditorialHelper\Service\ExtensionSettings;
use PHPUnit\Framework\TestCase;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;

final class ExtensionSettingsTest extends TestCase
{
    public function testReturnsDefaultsWhenConfigurationThrows(): void
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('get')->willThrowException(new \RuntimeException('not installed'));

        $settings = new ExtensionSettings($config);

        self::assertSame(ExtensionSettings::DEFAULT_ENDPOINT, $settings->getEndpoint());
        self::assertSame(ExtensionSettings::DEFAULT_MODEL, $settings->getModel());
        self::assertSame(ExtensionSettings::DEFAULT_TIMEOUT, $settings->getTimeout());
        self::assertSame('', $settings->getApiKey());
        self::assertFalse($settings->hasApiKey());
    }

    public function testReturnsDefaultsForEmptyValues(): void
    {
        $config = $this->mockExtensionConfig([
            'endpoint' => '',
            'model' => '   ',
            'timeout' => '',
            'apiKey' => '',
        ]);
        $settings = new ExtensionSettings($config);

        self::assertSame(ExtensionSettings::DEFAULT_ENDPOINT, $settings->getEndpoint());
        self::assertSame(ExtensionSettings::DEFAULT_MODEL, $settings->getModel());
        self::assertSame(ExtensionSettings::DEFAULT_TIMEOUT, $settings->getTimeout());
    }

    public function testTrimsTrailingSlashFromEndpoint(): void
    {
        $config = $this->mockExtensionConfig(['endpoint' => 'http://example.test:9000/v1/']);
        $settings = new ExtensionSettings($config);
        self::assertSame('http://example.test:9000/v1', $settings->getEndpoint());
    }

    public function testReadsCustomValues(): void
    {
        $config = $this->mockExtensionConfig([
            'endpoint' => 'http://lan.local:1234/v1',
            'model' => 'mistralai/magistral-small-2509',
            'timeout' => 30,
            'apiKey' => 'secret-token',
        ]);
        $settings = new ExtensionSettings($config);

        self::assertSame('http://lan.local:1234/v1', $settings->getEndpoint());
        self::assertSame('mistralai/magistral-small-2509', $settings->getModel());
        self::assertSame(30, $settings->getTimeout());
        self::assertSame('secret-token', $settings->getApiKey());
        self::assertTrue($settings->hasApiKey());
    }

    public function testNonPositiveTimeoutFallsBackToDefault(): void
    {
        $config = $this->mockExtensionConfig(['timeout' => -5]);
        $settings = new ExtensionSettings($config);
        self::assertSame(ExtensionSettings::DEFAULT_TIMEOUT, $settings->getTimeout());
    }

    private function mockExtensionConfig(array $values): ExtensionConfiguration
    {
        $config = $this->createMock(ExtensionConfiguration::class);
        $config->method('get')
            ->with(ExtensionSettings::EXT_KEY)
            ->willReturn($values);
        return $config;
    }
}
