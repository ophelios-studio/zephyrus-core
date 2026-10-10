<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Security;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
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

    public function testFromSecurityConfigSuggestsAShapeForAnExclusionThatIsNotBounded(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/security\.csrf\.exceptions\[0\] must end with .+ \(expected a shape such as #\^\/webhooks\/#\)\./');

        CsrfConfig::fromSecurityConfig($this->security(true, ['#^/webhooks#']));
    }

    public function testFromSecurityConfigRefusesAnEmptyExclusionByConfigurationKey(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('security.csrf.exceptions[0]');

        CsrfConfig::fromSecurityConfig($this->security(true, ['']));
    }

    public function testAnEmptyEnabledValueIsRefusedInsteadOfDisablingCsrf(): void
    {
        $this->expectException(ConfigurationException::class);

        CsrfConfig::fromArray(['enabled' => '']);
    }

    public function testAWhitespaceOnlyEnabledValueIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);

        CsrfConfig::fromArray(['csrf_enabled' => "  \t"]);
    }

    public function testTheStringZeroDisablesCsrfExplicitly(): void
    {
        self::assertFalse(CsrfConfig::fromArray(['enabled' => '0'])->enabled);
    }

    public function testAnEmptyInjectTokenValueIsRefusedInsteadOfReadAsOff(): void
    {
        $this->expectException(ConfigurationException::class);

        CsrfConfig::fromArray(['injectToken' => '']);
    }

    public function testAnInjectTokenValueOfOffIsAccepted(): void
    {
        self::assertFalse(CsrfConfig::fromArray(['inject_token' => 'off'])->injectToken);
    }

    public function testAnInjectTokenValueOfYesIsRefusedAsEnabled(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CsrfConfig::fromArray(['csrf_auto_html' => 'yes']);
    }

    public function testFromArrayRefusesAMisspelledKey(): void
    {
        try {
            CsrfConfig::fromArray(['enabeld' => false]);

            self::fail('A misspelled key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'csrf' field 'enabeld' is an unknown key: did you mean \"enabled\"?",
                $exception->getMessage(),
            );
        }
    }

    public function testFromArrayRefusesTwoSpellingsOfOneSetting(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Configuration section 'csrf' sets both 'bodyField' and 'body_field': keep one.");

        CsrfConfig::fromArray(['bodyField' => '_token', 'body_field' => '_csrf']);
    }

    public function testFromArrayRefusesADeclaredNullEnabled(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Configuration section 'csrf' field 'csrf_enabled' has invalid value null: is not a boolean");

        CsrfConfig::fromArray(['csrf_enabled' => null]);
    }

    public function testFromArrayRefusesADeclaredNullInjectToken(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Configuration section 'csrf' field 'injectToken' has invalid value null: is not a boolean");

        CsrfConfig::fromArray(['injectToken' => null]);
    }
}
