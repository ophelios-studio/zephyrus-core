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

        self::assertSame('en', $config->locale);
        self::assertSame([], $config->supportedLocales);
        self::assertNull($config->localePath);
        self::assertSame('UTC', $config->timezone);
        self::assertNull($config->currency);
    }

    public function testFromArrayNormalizesValuesAndSupportsSnakeCaseKeys(): void
    {
        $config = LocalizationConfig::fromArray([
            'locale' => ' FR-CA ',
            'supported_locales' => [' FR ', '', 'EN'],
            'locale_path' => ' /app/locales ',
            'timezone' => 'America/Montreal',
            'currency' => ' CAD ',
        ]);

        self::assertSame('fr-ca', $config->locale);
        self::assertSame(['fr', 'en'], $config->supportedLocales);
        self::assertSame('/app/locales', $config->localePath);
        self::assertSame('America/Montreal', $config->timezone);
        self::assertSame('CAD', $config->currency);
    }

    public function testFromArrayRejectsEmptyLocale(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['locale' => '   ']);
    }

    public function testFromArrayRejectsEmptyTimezone(): void
    {
        $this->expectException(ConfigurationException::class);

        LocalizationConfig::fromArray(['timezone' => '']);
    }

    public function testFromArrayLegacyDefaultLocaleKeyIsAccepted(): void
    {
        $config = LocalizationConfig::fromArray([
            'defaultLocale' => 'fr',
        ]);

        self::assertSame('fr', $config->locale);
    }

    public function testFromArrayLegacySnakeCaseDefaultLocaleKeyIsAccepted(): void
    {
        $config = LocalizationConfig::fromArray([
            'default_locale' => 'de',
        ]);

        self::assertSame('de', $config->locale);
    }

    public function testFromArrayLegacyJsonLocalePathsConvertsToSinglePath(): void
    {
        $config = LocalizationConfig::fromArray([
            'jsonLocalePaths' => ['/base/locales', '/override/locales'],
        ]);

        // Takes last non-empty path
        self::assertSame('/override/locales', $config->localePath);
    }

    public function testFromArrayLegacySnakeCaseJsonLocalePathsConvertsToSinglePath(): void
    {
        $config = LocalizationConfig::fromArray([
            'json_locale_paths' => ['/app/locales'],
        ]);

        self::assertSame('/app/locales', $config->localePath);
    }

    public function testFromArrayLegacyEmptyJsonLocalePathsResultsInNull(): void
    {
        $config = LocalizationConfig::fromArray([
            'jsonLocalePaths' => [],
        ]);

        self::assertNull($config->localePath);
    }

    public function testFromArrayNewLocalePathTakesPrecedenceOverLegacy(): void
    {
        $config = LocalizationConfig::fromArray([
            'localePath' => '/new/path',
            'jsonLocalePaths' => ['/old/path'],
        ]);

        self::assertSame('/new/path', $config->localePath);
    }

    public function testFromArrayEmptyCurrencyNormalizesToNull(): void
    {
        $config = LocalizationConfig::fromArray([
            'currency' => '',
        ]);

        self::assertNull($config->currency);
    }

    public function testFromArrayNullLocalePathResultsInNull(): void
    {
        $config = LocalizationConfig::fromArray([
            'localePath' => '',
        ]);

        self::assertNull($config->localePath);
    }
}
