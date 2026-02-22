<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\SecurityConfig;

final class SecurityConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    public function testBuildsWithDefaults(): void
    {
        $config = SecurityConfig::fromArray([]);

        self::assertFalse($config->forceHttps);
        self::assertTrue($config->csrfEnabled);
        self::assertSame([], $config->allowedHosts);
        self::assertSame(2_097_152, $config->maxBodySize);
    }

    // -------------------------------------------------------------------------
    // camelCase keys
    // -------------------------------------------------------------------------

    public function testAcceptsCamelCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'forceHttps'   => true,
            'csrfEnabled'  => false,
            'allowedHosts' => ['example.com', 'api.example.com'],
            'maxBodySize'  => 1_048_576,
        ]);

        self::assertTrue($config->forceHttps);
        self::assertFalse($config->csrfEnabled);
        self::assertSame(['example.com', 'api.example.com'], $config->allowedHosts);
        self::assertSame(1_048_576, $config->maxBodySize);
    }

    // -------------------------------------------------------------------------
    // snake_case key variants
    // -------------------------------------------------------------------------

    public function testAcceptsSnakeCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'force_https'   => true,
            'csrf_enabled'  => false,
            'allowed_hosts' => ['app.local'],
            'max_body_size' => 512,
        ]);

        self::assertTrue($config->forceHttps);
        self::assertFalse($config->csrfEnabled);
        self::assertSame(['app.local'], $config->allowedHosts);
        self::assertSame(512, $config->maxBodySize);
    }

    // -------------------------------------------------------------------------
    // camelCase takes precedence over snake_case when both are present
    // -------------------------------------------------------------------------

    public function testCamelCaseTakesPrecedenceOverSnakeCase(): void
    {
        $config = SecurityConfig::fromArray([
            'forceHttps'  => true,
            'force_https' => false,
        ]);

        self::assertTrue($config->forceHttps);
    }

    // -------------------------------------------------------------------------
    // maxBodySize zero = unlimited
    // -------------------------------------------------------------------------

    public function testMaxBodySizeZeroIsUnlimited(): void
    {
        $config = SecurityConfig::fromArray(['maxBodySize' => 0]);

        self::assertSame(0, $config->maxBodySize);
    }

    // -------------------------------------------------------------------------
    // allowedHosts index reindexing
    // -------------------------------------------------------------------------

    public function testAllowedHostsAreReindexed(): void
    {
        // Passing an assoc array should still produce a numeric list.
        $config = SecurityConfig::fromArray([
            'allowedHosts' => [5 => 'a.test', 10 => 'b.test'],
        ]);

        self::assertSame(['a.test', 'b.test'], $config->allowedHosts);
    }

    // -------------------------------------------------------------------------
    // Validation failures
    // -------------------------------------------------------------------------

    public function testThrowsForNegativeMaxBodySize(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("maxBodySize");

        SecurityConfig::fromArray(['maxBodySize' => -1]);
    }

    public function testThrowsForEmptyStringInAllowedHosts(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("allowedHosts");

        SecurityConfig::fromArray(['allowedHosts' => ['valid.com', '']]);
    }

    public function testThrowsForNonStringInAllowedHosts(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['allowedHosts' => [42]]);
    }
}
