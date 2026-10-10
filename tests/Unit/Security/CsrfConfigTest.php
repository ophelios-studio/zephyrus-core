<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\SecurityConfig;
use Zephyrus\Security\CsrfConfig;

final class CsrfConfigTest extends TestCase
{
    private function security(bool $csrfEnabled, array $csrfExceptions): SecurityConfig
    {
        return new SecurityConfig(
            forceHttps: false,
            csrfEnabled: $csrfEnabled,
            csrfAutoHtml: false,
            csrfExceptions: $csrfExceptions,
            allowedHosts: [],
            maxBodySize: 0,
        );
    }

    public function testFromSecurityConfigCopiesExclusionsAsRegexPatterns(): void
    {
        $config = CsrfConfig::fromSecurityConfig($this->security(true, ['#^/webhooks/#', '#^/logout$#']));

        self::assertSame(['#^/webhooks/#', '#^/logout$#'], $config->excludedPathPatterns);
    }

    public function testFromSecurityConfigMapsDisabledCsrf(): void
    {
        $config = CsrfConfig::fromSecurityConfig($this->security(false, []));

        self::assertFalse($config->enabled);
    }

    public function testFromSecurityConfigKeepsOtherFieldsAtTheirDefaults(): void
    {
        $config = CsrfConfig::fromSecurityConfig($this->security(true, []));

        self::assertSame('_csrf_token', $config->bodyField);
        self::assertSame('X-CSRF-Token', $config->headerName);
        self::assertFalse($config->injectToken);
        self::assertTrue($config->enabled);
    }

    public function testFromSecurityConfigRefusesAnUnanchoredExclusion(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CsrfConfig::fromSecurityConfig($this->security(true, ['#/webhooks/#']));
    }

    public function testFromSecurityConfigNamesTheConfigurationKeyOfARefusedExclusion(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/security\.csrf\.exceptions\[1\] must start with .+ \/webhooks\/ \(expected a shape such as #\^\/webhooks\/#\)\./');

        CsrfConfig::fromSecurityConfig($this->security(true, ['#^/ok/#', '/webhooks/']));
    }

    public function testFromSecurityConfigRefusesAnEmptyExclusionByConfigurationKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('security.csrf.exceptions[0]');

        CsrfConfig::fromSecurityConfig($this->security(true, ['']));
    }
}
