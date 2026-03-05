<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\LocalizationConfig;

final class LocalizationConfigTest extends TestCase
{
    public function testFromArrayDefaultsAreApplied(): void
    {
        $config = LocalizationConfig::fromArray([]);

        self::assertSame('en', $config->defaultLocale);
        self::assertSame([], $config->supportedLocales);
        self::assertSame([], $config->jsonLocalePaths);
        self::assertSame('json', $config->jsonExtension);
    }

    public function testFromArrayNormalizesValuesAndSupportsSnakeCaseKeys(): void
    {
        $config = LocalizationConfig::fromArray([
            'default_locale' => ' FR-CA ',
            'supported_locales' => [' FR ', '', 'EN'],
            'json_locale_paths' => [' /app/locales ', '', '/vendor/locales'],
            'json_extension' => '.JSON',
        ]);

        self::assertSame('fr-ca', $config->defaultLocale);
        self::assertSame(['fr', 'en'], $config->supportedLocales);
        self::assertSame(['/app/locales', '/vendor/locales'], $config->jsonLocalePaths);
        self::assertSame('json', $config->jsonExtension);
    }

    public function testFromArrayRejectsEmptyDefaultLocale(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['defaultLocale' => '   ']);
    }

    public function testFromArrayRejectsEmptyJsonExtension(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['jsonExtension' => '']);
    }
}
