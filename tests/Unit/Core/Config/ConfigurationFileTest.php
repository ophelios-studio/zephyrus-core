<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Zephyrus\Core\Config\ConfigurationFile;

final class ConfigurationFileTest extends TestCase
{
    private string $fixturesDir;

    protected function setUp(): void
    {
        $this->fixturesDir = __DIR__ . '/fixtures';
    }

    public function testParsesYamlFileSuccessfully(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');
        $data = $file->toArray();

        self::assertArrayHasKey('application', $data);
        self::assertArrayHasKey('database', $data);
        self::assertSame('development', $data['application']['environment']);
        self::assertTrue($data['application']['debug']);
    }

    public function testReadReturnsSpecificSection(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        $db = $file->read('database');
        self::assertIsArray($db);
        self::assertSame('localhost', $db['host']);
        self::assertSame(5432, $db['port']);
    }

    public function testReadReturnsNullForMissingSection(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        self::assertNull($file->read('nonexistent'));
    }

    public function testReadReturnsFullConfigWhenSectionIsNull(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        $full = $file->read(null);
        self::assertIsArray($full);
        self::assertArrayHasKey('application', $full);
    }

    public function testHasSectionWorks(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        self::assertTrue($file->hasSection('application'));
        self::assertTrue($file->hasSection('database'));
        self::assertFalse($file->hasSection('missing'));
    }

    public function testSectionsReturnsSectionNames(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');
        $sections = $file->sections();

        self::assertContains('application', $sections);
        self::assertContains('session', $sections);
        self::assertContains('security', $sections);
        self::assertContains('database', $sections);
    }

    public function testResolvesEnvTagWithFallback(): void
    {
        // Env variable not set, should use fallback
        unset($_ENV['TEST_DB_HOST'], $_SERVER['TEST_DB_HOST']);

        $file = new ConfigurationFile($this->fixturesDir . '/test-config-env.yml');
        $db = $file->read('database');

        self::assertSame('fallback-host', $db['host']);
        self::assertSame('default_user', $db['username']);
    }

    public function testResolvesEnvTagFromEnvironment(): void
    {
        $_ENV['TEST_DB_HOST'] = 'env-host.example.com';
        $_ENV['TEST_DB_NAME'] = 'env_database';

        try {
            // Force re-parse by creating a new instance
            $file = new ConfigurationFile($this->fixturesDir . '/test-config-env.yml');
            $db = $file->read('database');

            self::assertSame('env-host.example.com', $db['host']);
            self::assertSame('env_database', $db['database']);
        } finally {
            unset($_ENV['TEST_DB_HOST'], $_ENV['TEST_DB_NAME']);
        }
    }

    public function testResolvesEnvTagReturnsNullWhenNoDefault(): void
    {
        unset($_ENV['TEST_DB_NAME'], $_SERVER['TEST_DB_NAME']);

        $file = new ConfigurationFile($this->fixturesDir . '/test-config-env.yml');
        $db = $file->read('database');

        self::assertNull($db['database']);
    }

    public function testThrowsForMissingFile(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Configuration file not found');

        $file = new ConfigurationFile('/nonexistent/path/config.yml');
        $file->toArray();
    }

    public function testCachesResultAfterFirstParse(): void
    {
        $file = new ConfigurationFile($this->fixturesDir . '/test-config.yml');

        $first = $file->toArray();
        $second = $file->toArray();

        self::assertSame($first, $second);
    }
}
