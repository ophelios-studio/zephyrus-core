<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigSection;
use Zephyrus\Core\Config\Configuration;
use Zephyrus\Core\Config\Environment;

/**
 * Tests for YAML-based configuration loading, !env tag resolution,
 * multi-file merging, and custom section registration.
 */
final class ConfigurationYamlTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testFromYamlFileLoadsConfiguration(): void
    {
        $config = Configuration::fromYamlFile($this->fixturesDir . '/test-config.yml');

        self::assertSame(Environment::Development, $config->application->environment);
        self::assertTrue($config->application->debug);
        self::assertSame('ZEPHYRUS_SID', $config->session->name);
        self::assertSame(3600, $config->session->lifetime);
        self::assertNotNull($config->database);
        self::assertSame('localhost', $config->database->host);
        self::assertSame(5432, $config->database->port);
        self::assertSame('test_db', $config->database->database);
    }

    public function testFromYamlFilesOverridesWithLaterFiles(): void
    {
        $config = Configuration::fromYamlFiles([
            $this->fixturesDir . '/test-config.yml',
            $this->fixturesDir . '/test-config-override.yml',
        ]);

        // Override: debug changed to false
        self::assertFalse($config->application->debug);
        // Override: host changed
        self::assertSame('production-host', $config->database?->host);
        // Retained from base: port unchanged
        self::assertSame(5432, $config->database?->port);
    }

    public function testFromOptionalYamlFilesSkipsMissingFiles(): void
    {
        $config = Configuration::fromOptionalYamlFiles([
            $this->fixturesDir . '/test-config.yml',
            $this->fixturesDir . '/nonexistent.yml',
        ]);

        self::assertSame(Environment::Development, $config->application->environment);
    }

    public function testFromFileDetectsYamlByExtension(): void
    {
        $config = Configuration::fromFile($this->fixturesDir . '/test-config.yml');

        self::assertSame(Environment::Development, $config->application->environment);
    }

    public function testEnvTagResolution(): void
    {
        $_ENV['TEST_DB_HOST'] = 'from-env.example.com';
        $_ENV['TEST_DB_NAME'] = 'env_db';

        try {
            $config = Configuration::fromYamlFile($this->fixturesDir . '/test-config-env.yml');

            self::assertSame('from-env.example.com', $config->database?->host);
            self::assertSame('env_db', $config->database?->database);
            self::assertSame('default_user', $config->database?->username);
        } finally {
            unset($_ENV['TEST_DB_HOST'], $_ENV['TEST_DB_NAME']);
        }
    }

    public function testCustomSectionRegistration(): void
    {
        $config = Configuration::fromYamlFile(
            $this->fixturesDir . '/test-config-custom.yml',
            sectionFactories: [
                'custom_app' => CustomAppConfig::class,
            ],
        );

        self::assertTrue($config->hasSection('customApp'));
        $custom = $config->section('customApp');
        self::assertInstanceOf(CustomAppConfig::class, $custom);
        self::assertSame('TestApp', $custom->getString('name'));
        self::assertSame('2.0', $custom->getString('version'));
        self::assertFalse($custom->getBool('maintenance'));
        self::assertTrue($custom->getBool('features.darkMode'));
        self::assertSame(100, $custom->getInt('features.apiRateLimit'));
    }

    public function testSectionReturnsNullForUnregisteredSection(): void
    {
        $config = Configuration::fromArray([]);

        self::assertNull($config->section('nonexistent'));
        self::assertFalse($config->hasSection('nonexistent'));
    }

    public function testCustomSectionsIncludedInToArray(): void
    {
        $config = Configuration::fromArray([
            'custom_app' => ['name' => 'Test'],
        ], sectionFactories: [
            'custom_app' => CustomAppConfig::class,
        ]);

        $array = $config->toArray();
        self::assertArrayHasKey('customApp', $array);
        self::assertSame('Test', $array['customApp']['name']);
    }

    public function testBuiltInSectionNamesCannotBeOverriddenByCustomFactories(): void
    {
        // 'application' is a built-in section and should not be overridden
        $config = Configuration::fromArray([
            'application' => ['environment' => 'testing', 'debug' => true],
        ], sectionFactories: [
            'application' => CustomAppConfig::class, // Should be ignored
        ]);

        self::assertFalse($config->hasSection('application'));
        self::assertSame(Environment::Testing, $config->application->environment);
    }
}

/**
 * Test custom section class.
 */
final class CustomAppConfig extends ConfigSection
{
    public static function fromArray(array $values): static
    {
        return new static($values);
    }
}
