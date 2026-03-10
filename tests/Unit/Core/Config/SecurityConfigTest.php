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
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame([], $config->csrfExceptions);
        self::assertSame([], $config->allowedHosts);
        self::assertSame(2_097_152, $config->maxBodySize);
        self::assertNull($config->encryptionKey);
    }

    // -------------------------------------------------------------------------
    // camelCase keys
    // -------------------------------------------------------------------------

    public function testAcceptsCamelCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'forceHttps' => true,
            'csrfEnabled' => false,
            'csrfAutoHtml' => true,
            'csrfExceptions' => ['#^/webhooks/#'],
            'allowedHosts' => ['example.com', 'api.example.com'],
            'maxBodySize' => 1_048_576,
        ]);

        self::assertTrue($config->forceHttps);
        self::assertFalse($config->csrfEnabled);
        self::assertTrue($config->csrfAutoHtml);
        self::assertSame(['#^/webhooks/#'], $config->csrfExceptions);
        self::assertSame(['example.com', 'api.example.com'], $config->allowedHosts);
        self::assertSame(1_048_576, $config->maxBodySize);
    }

    // -------------------------------------------------------------------------
    // snake_case key variants
    // -------------------------------------------------------------------------

    public function testAcceptsSnakeCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'force_https' => true,
            'csrf_enabled' => false,
            'csrf_auto_html' => true,
            'csrf_exceptions' => ['#^/hooks/#'],
            'allowed_hosts' => ['app.local'],
            'max_body_size' => 512,
        ]);

        self::assertTrue($config->forceHttps);
        self::assertFalse($config->csrfEnabled);
        self::assertTrue($config->csrfAutoHtml);
        self::assertSame(['#^/hooks/#'], $config->csrfExceptions);
        self::assertSame(['app.local'], $config->allowedHosts);
        self::assertSame(512, $config->maxBodySize);
    }

    // -------------------------------------------------------------------------
    // camelCase takes precedence over snake_case when both are present
    // -------------------------------------------------------------------------

    public function testCamelCaseTakesPrecedenceOverSnakeCase(): void
    {
        $config = SecurityConfig::fromArray([
            'forceHttps' => true,
            'force_https' => false,
            'csrfAutoHtml' => true,
            'csrf_auto_html' => false,
            'csrfExceptions' => ['#^/camel/#'],
            'csrf_exceptions' => ['#^/snake/#'],
        ]);

        self::assertTrue($config->forceHttps);
        self::assertTrue($config->csrfAutoHtml);
        self::assertSame(['#^/camel/#'], $config->csrfExceptions);
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
    // list index reindexing
    // -------------------------------------------------------------------------

    public function testAllowedHostsAreReindexed(): void
    {
        $config = SecurityConfig::fromArray([
            'allowedHosts' => [5 => 'a.test', 10 => 'b.test'],
        ]);

        self::assertSame(['a.test', 'b.test'], $config->allowedHosts);
    }

    public function testCsrfExceptionsAreReindexed(): void
    {
        $config = SecurityConfig::fromArray([
            'csrfExceptions' => [5 => '#^/a$#', 10 => '#^/b$#'],
        ]);

        self::assertSame(['#^/a$#', '#^/b$#'], $config->csrfExceptions);
    }

    // -------------------------------------------------------------------------
    // Validation failures
    // -------------------------------------------------------------------------

    public function testThrowsForNegativeMaxBodySize(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('maxBodySize');

        SecurityConfig::fromArray(['maxBodySize' => -1]);
    }

    public function testThrowsForEmptyStringInAllowedHosts(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('allowedHosts');

        SecurityConfig::fromArray(['allowedHosts' => ['valid.com', '']]);
    }

    public function testThrowsForNonStringInAllowedHosts(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['allowedHosts' => [42]]);
    }

    public function testThrowsForEmptyStringInCsrfExceptions(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('csrfExceptions');

        SecurityConfig::fromArray(['csrfExceptions' => ['#^/ok$#', '']]);
    }

    public function testThrowsForNonStringInCsrfExceptions(): void
    {
        $this->expectException(ConfigurationException::class);

        SecurityConfig::fromArray(['csrfExceptions' => [123]]);
    }

    // ── Trusted Proxies ──────────────────────────────────────────────

    public function testTrustedProxiesDefaultsToEmpty(): void
    {
        $config = SecurityConfig::fromArray([]);
        self::assertSame([], $config->trustedProxies);
    }

    public function testTrustedProxiesAcceptsCamelCase(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedProxies' => ['127.0.0.1', '10.0.0.0/8'],
        ]);

        self::assertSame(['127.0.0.1', '10.0.0.0/8'], $config->trustedProxies);
    }

    public function testTrustedProxiesAcceptsSnakeCase(): void
    {
        $config = SecurityConfig::fromArray([
            'trusted_proxies' => ['127.0.0.1'],
        ]);

        self::assertSame(['127.0.0.1'], $config->trustedProxies);
    }

    public function testTrustedProxiesWildcard(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedProxies' => ['*'],
        ]);

        self::assertSame(['*'], $config->trustedProxies);
    }

    public function testThrowsForEmptyStringInTrustedProxies(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('trustedProxies');

        SecurityConfig::fromArray(['trustedProxies' => ['127.0.0.1', '']]);
    }

    public function testTrustedProxiesAreReindexed(): void
    {
        $config = SecurityConfig::fromArray([
            'trustedProxies' => [5 => '10.0.0.1', 10 => '10.0.0.2'],
        ]);

        self::assertSame(['10.0.0.1', '10.0.0.2'], $config->trustedProxies);
    }

    // ── Nested CSRF section ──────────────────────────────────────────

    public function testNestedCsrfSectionTakesPrecedenceOverFlatKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'csrfEnabled' => true,  // flat key
            'csrf' => [
                'enabled' => false,  // nested takes precedence
                'autoHtml' => true,
                'exceptions' => ['#^/api/#'],
            ],
        ]);

        self::assertFalse($config->csrfEnabled);
        self::assertTrue($config->csrfAutoHtml);
        self::assertSame(['#^/api/#'], $config->csrfExceptions);
    }

    public function testNestedCsrfSectionWithSnakeCaseKeys(): void
    {
        $config = SecurityConfig::fromArray([
            'csrf' => [
                'auto_html' => true,
            ],
        ]);

        self::assertTrue($config->csrfAutoHtml);
    }

    public function testNestedCsrfSectionDefaults(): void
    {
        $config = SecurityConfig::fromArray([
            'csrf' => [],
        ]);

        self::assertTrue($config->csrfEnabled);
        self::assertFalse($config->csrfAutoHtml);
        self::assertSame([], $config->csrfExceptions);
    }

    // ── Encryption section ──────────────────────────────────────────

    public function testEncryptionKeyFromNestedSection(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption' => [
                'key' => 'my-secret-key-32-chars-long!!!!!',
            ],
        ]);

        self::assertSame('my-secret-key-32-chars-long!!!!!', $config->encryptionKey);
    }

    public function testEncryptionKeyFromFlatCamelCase(): void
    {
        $config = SecurityConfig::fromArray([
            'encryptionKey' => 'flat-key-value',
        ]);

        self::assertSame('flat-key-value', $config->encryptionKey);
    }

    public function testEncryptionKeyFromFlatSnakeCase(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption_key' => 'snake-key-value',
        ]);

        self::assertSame('snake-key-value', $config->encryptionKey);
    }

    public function testEncryptionKeyNestedTakesPrecedenceOverFlat(): void
    {
        $config = SecurityConfig::fromArray([
            'encryptionKey' => 'flat-value',
            'encryption' => [
                'key' => 'nested-value',
            ],
        ]);

        self::assertSame('nested-value', $config->encryptionKey);
    }

    public function testEncryptionKeyEmptyStringNormalizesToNull(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption' => ['key' => '   '],
        ]);

        self::assertNull($config->encryptionKey);
    }

    public function testEncryptionKeyNonStringNormalizesToNull(): void
    {
        $config = SecurityConfig::fromArray([
            'encryption' => ['key' => 123],
        ]);

        self::assertNull($config->encryptionKey);
    }
}
