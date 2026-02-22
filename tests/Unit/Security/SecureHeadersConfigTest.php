<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use Zephyrus\Security\SecureHeadersConfig;

final class SecureHeadersConfigTest extends TestCase
{
    // ── defaults() ───────────────────────────────────────────────────────────

    public function testDefaultsXFrameOptions(): void
    {
        self::assertSame('SAMEORIGIN', SecureHeadersConfig::defaults()->xFrameOptions);
    }

    public function testDefaultsXContentTypeOptions(): void
    {
        self::assertSame('nosniff', SecureHeadersConfig::defaults()->xContentTypeOptions);
    }

    public function testDefaultsReferrerPolicy(): void
    {
        self::assertSame('strict-origin-when-cross-origin', SecureHeadersConfig::defaults()->referrerPolicy);
    }

    public function testDefaultsXssProtection(): void
    {
        self::assertSame('0', SecureHeadersConfig::defaults()->xssProtection);
    }

    public function testDefaultsHstsDisabled(): void
    {
        $config = SecureHeadersConfig::defaults();
        self::assertSame(0, $config->hstsMaxAge);
        self::assertFalse($config->hstsIncludeSubdomains);
    }

    public function testDefaultsCspEmpty(): void
    {
        self::assertSame('', SecureHeadersConfig::defaults()->csp);
    }

    public function testDefaultsPermissionsPolicyEmpty(): void
    {
        self::assertSame('', SecureHeadersConfig::defaults()->permissionsPolicy);
    }

    // ── fromArray() — camelCase keys ─────────────────────────────────────────

    public function testFromArrayCamelCaseKeys(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'xFrameOptions'       => 'DENY',
            'xContentTypeOptions' => 'nosniff',
            'referrerPolicy'      => 'no-referrer',
            'xssProtection'       => '1; mode=block',
            'hstsMaxAge'          => 31_536_000,
            'hstsIncludeSubdomains' => true,
            'csp'                 => "default-src 'self'",
            'permissionsPolicy'   => 'camera=()',
        ]);

        self::assertSame('DENY', $config->xFrameOptions);
        self::assertSame('nosniff', $config->xContentTypeOptions);
        self::assertSame('no-referrer', $config->referrerPolicy);
        self::assertSame('1; mode=block', $config->xssProtection);
        self::assertSame(31_536_000, $config->hstsMaxAge);
        self::assertTrue($config->hstsIncludeSubdomains);
        self::assertSame("default-src 'self'", $config->csp);
        self::assertSame('camera=()', $config->permissionsPolicy);
    }

    // ── fromArray() — snake_case keys ────────────────────────────────────────

    public function testFromArraySnakeCaseKeys(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'x_frame_options'         => 'DENY',
            'x_content_type_options'  => 'nosniff',
            'referrer_policy'         => 'no-referrer',
            'xss_protection'          => '1',
            'hsts_max_age'            => 86_400,
            'hsts_include_subdomains' => true,
            'permissions_policy'      => 'microphone=()',
        ]);

        self::assertSame('DENY', $config->xFrameOptions);
        self::assertSame('nosniff', $config->xContentTypeOptions);
        self::assertSame('no-referrer', $config->referrerPolicy);
        self::assertSame('1', $config->xssProtection);
        self::assertSame(86_400, $config->hstsMaxAge);
        self::assertTrue($config->hstsIncludeSubdomains);
        self::assertSame('microphone=()', $config->permissionsPolicy);
    }

    public function testFromArrayCamelCaseTakesPrecedenceOverSnakeCase(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'xFrameOptions' => 'DENY',
            'x_frame_options' => 'SAMEORIGIN',
        ]);

        self::assertSame('DENY', $config->xFrameOptions);
    }

    public function testFromArrayMissingKeysUsesDefaults(): void
    {
        $config = SecureHeadersConfig::fromArray([]);
        $defaults = SecureHeadersConfig::defaults();

        self::assertSame($defaults->xFrameOptions, $config->xFrameOptions);
        self::assertSame($defaults->xContentTypeOptions, $config->xContentTypeOptions);
        self::assertSame($defaults->referrerPolicy, $config->referrerPolicy);
        self::assertSame($defaults->xssProtection, $config->xssProtection);
        self::assertSame($defaults->hstsMaxAge, $config->hstsMaxAge);
        self::assertSame($defaults->hstsIncludeSubdomains, $config->hstsIncludeSubdomains);
        self::assertSame($defaults->csp, $config->csp);
        self::assertSame($defaults->permissionsPolicy, $config->permissionsPolicy);
    }

    public function testFromArrayEmptyStringDisablesHeader(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'xFrameOptions' => '',
            'xContentTypeOptions' => '',
        ]);

        self::assertSame('', $config->xFrameOptions);
        self::assertSame('', $config->xContentTypeOptions);
    }

    // ── hstsHeaderValue() ────────────────────────────────────────────────────

    public function testHstsHeaderValueEmptyWhenDisabled(): void
    {
        self::assertSame('', SecureHeadersConfig::defaults()->hstsHeaderValue());
    }

    public function testHstsHeaderValueWithMaxAgeOnly(): void
    {
        $config = SecureHeadersConfig::fromArray(['hstsMaxAge' => 31_536_000]);
        self::assertSame('max-age=31536000', $config->hstsHeaderValue());
    }

    public function testHstsHeaderValueWithIncludeSubdomains(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'hstsMaxAge'            => 31_536_000,
            'hstsIncludeSubdomains' => true,
        ]);
        self::assertSame('max-age=31536000; includeSubDomains', $config->hstsHeaderValue());
    }

    public function testHstsHeaderValueZeroMaxAgeReturnsEmpty(): void
    {
        $config = SecureHeadersConfig::fromArray([
            'hstsMaxAge'            => 0,
            'hstsIncludeSubdomains' => true,
        ]);
        self::assertSame('', $config->hstsHeaderValue());
    }
}
