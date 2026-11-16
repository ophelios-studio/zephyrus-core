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
    private const string UNCLOSED_REFUSAL = "CSRF excluded path pattern at index 0 is not a valid regex: /unclosed "
        . "(No ending delimiter '/' found; expected a shape such as #^/webhooks/#).";

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

    public function testAnUnclosedPatternIsRefusedWithTheFrameworkMessage(): void
    {
        try {
            new CsrfConfig(excludedPathPatterns: ['/unclosed']);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(self::UNCLOSED_REFUSAL, $e->getMessage());
        }
    }

    public function testAnUnclosedPatternIsRefusedWithTheFrameworkMessageUnderAThrowingErrorHandler(): void
    {
        set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        });

        try {
            new CsrfConfig(excludedPathPatterns: ['/unclosed']);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(self::UNCLOSED_REFUSAL, $e->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    public function testTheReasonAndThePatternOfARefusedPatternAreEscaped(): void
    {
        try {
            new CsrfConfig(excludedPathPatterns: ["#a#\x1b"]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                "CSRF excluded path pattern at index 0 is not a valid regex: #a#\\u001b "
                . "(Unknown modifier '\\u001b'; expected a shape such as #^/webhooks/#).",
                $e->getMessage(),
            );
        }
    }

    public function testControlCharactersOfARefusedPatternCannotForgeALogLine(): void
    {
        try {
            new CsrfConfig(excludedPathPatterns: ["/a\e[2K\r\nFAKE"]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                "CSRF excluded path pattern at index 0 is not a valid regex: /a\\u001b[2K\\r\\nFAKE "
                . "(No ending delimiter '/' found; expected a shape such as #^/webhooks/#).",
                $e->getMessage(),
            );
        }
    }

    public function testControlCharactersOfAPatternRefusedAsNotDelimitedAreEscaped(): void
    {
        try {
            new CsrfConfig(excludedPathPatterns: ["\r\n#^/a/#"]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'CSRF excluded path pattern at index 0 is not a delimited regex: \\r\\n#^/a/# '
                . '(expected a shape such as #^/webhooks/#).',
                $e->getMessage(),
            );
        }
    }

    public function testControlCharactersOfAPatternRefusedAsUnanchoredAreEscaped(): void
    {
        try {
            new CsrfConfig(excludedPathPatterns: ["#a\e\r\nFAKE#"]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'CSRF excluded path pattern at index 0 must start with "^" so it cannot match in the middle of a path: '
                . '#a\\u001b\\r\\nFAKE# (expected a shape such as #^/webhooks/#).',
                $e->getMessage(),
            );
        }
    }

    public function testControlCharactersOfAPatternRefusedAsOpenEndedAreEscaped(): void
    {
        try {
            new CsrfConfig(excludedPathPatterns: ["#^/a\e\r\nFAKE#"]);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'CSRF excluded path pattern at index 0 must end with "/" (the route and everything under it) or "$" '
                . '(that exact path), otherwise it also exempts every sibling route sharing the prefix: '
                . '#^/a\\u001b\\r\\nFAKE# (expected a shape such as #^/webhooks/#).',
                $e->getMessage(),
            );
        }
    }

    public function testControlCharactersOfTheBodyFieldAreEscapedInTheInjectionRefusal(): void
    {
        try {
            new CsrfConfig(bodyField: "_t\r\nX", injectToken: true);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'Automatic token injection is not supported because it cannot follow the browser\'s HTML parsing and '
                . 'could send the token to another site. Add a hidden "_t\\r\\nX" field to your form templates, filled '
                . 'with the escaped value of CsrfTokenManagerInterface::getToken().',
                $e->getMessage(),
            );
        }
    }

    public function testControlCharactersOfAPatternKeyAreEscapedInTheRefusal(): void
    {
        try {
            new CsrfConfig(excludedPathPatterns: ["a\nb" => '#^/webhooks#']);
            self::fail('expected a refusal');
        } catch (InvalidArgumentException $e) {
            self::assertSame(
                'CSRF excluded path pattern at index a\\nb must end with "/" (the route and everything under it) or "$" '
                . '(that exact path), otherwise it also exempts every sibling route sharing the prefix: #^/webhooks# '
                . '(expected a shape such as #^/webhooks/#).',
                $e->getMessage(),
            );
        }
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

    public function testAnUnknownKeyNearNoSettingListsTheSettingsThatAcceptMoreThanFalse(): void
    {
        try {
            CsrfConfig::fromArray(['timeout' => 5]);

            self::fail('An unknown key was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'csrf' field 'timeout' is an unknown key: the accepted keys are enabled, "
                    . 'bodyField, headerName, excludedPathPatterns.',
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
