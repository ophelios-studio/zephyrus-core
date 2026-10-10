<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\SessionConfig;

final class SessionConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

    public function testBuildsWithDefaults(): void
    {
        $config = SessionConfig::fromArray([]);

        self::assertSame('PHPSESSID', $config->name);
        self::assertSame(0,           $config->lifetime);
        self::assertTrue($config->httpOnly);
        self::assertFalse($config->secure);
        self::assertSame('Lax',       $config->sameSite);
        self::assertSame('/',         $config->cookiePath);
    }

    // -------------------------------------------------------------------------
    // secure: the three states
    // -------------------------------------------------------------------------

    public function testSecureDefaultsToAutoSoAnHttpsRequestGetsASecureCookie(): void
    {
        $config = SessionConfig::fromArray([]);

        self::assertTrue($config->secureAuto);
        self::assertTrue($config->resolveSecure(true));
        self::assertFalse($config->resolveSecure(false));
    }

    public function testAnExplicitFalseTurnsAutoOffForLocalHttpDevelopment(): void
    {
        $config = SessionConfig::fromArray(['secure' => false]);

        self::assertFalse($config->secureAuto);
        self::assertFalse($config->resolveSecure(true));
    }

    public function testAnExplicitTrueIsAlwaysSecure(): void
    {
        $config = SessionConfig::fromArray(['secure' => true]);

        self::assertFalse($config->secureAuto);
        self::assertTrue($config->resolveSecure(false));
    }

    public function testTheStringAutoSelectsTheAutomaticMode(): void
    {
        $config = SessionConfig::fromArray(['secure' => 'auto']);

        self::assertTrue($config->secureAuto);
        self::assertTrue($config->resolveSecure(true));
        self::assertFalse($config->resolveSecure(false));
    }

    public function testDirectConstructionDoesNotOptIntoAuto(): void
    {
        $config = new SessionConfig('PHPSESSID', 0, true, false, 'Lax', '/');

        self::assertFalse($config->secureAuto);
        self::assertFalse($config->resolveSecure(true));
    }

    // -------------------------------------------------------------------------
    // Combinations a browser would silently discard
    // -------------------------------------------------------------------------

    public function testSameSiteNoneWithAnExplicitInsecureCookieIsRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sameSite');

        SessionConfig::fromArray(['sameSite' => 'None', 'secure' => false]);
    }

    public function testSameSiteNoneIsAllowedWhenSecureCanStillApply(): void
    {
        // SameSite=None requires Secure: "auto" keeps it on HTTPS origins.
        $config = SessionConfig::fromArray(['sameSite' => 'None']);

        self::assertSame('None', $config->sameSite);
        self::assertTrue($config->resolveSecure(true));
    }

    public function testHostPrefixedNameRequiresARootPath(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('cookiePath');

        SessionConfig::fromArray(['name' => '__Host-SESSION', 'cookiePath' => '/app', 'secure' => true]);
    }

    public function testHostPrefixedNameRequiresSecure(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('__Host-');

        SessionConfig::fromArray(['name' => '__Host-SESSION', 'secure' => false]);
    }

    public function testSecurePrefixedNameRequiresSecure(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('__Secure-');

        SessionConfig::fromArray(['name' => '__Secure-SESSION', 'secure' => false]);
    }

    public function testAPrefixedNameIsAcceptedWhenTheAttributesAreCoherent(): void
    {
        $config = SessionConfig::fromArray(['name' => '__Host-SESSION', 'secure' => true]);

        self::assertSame('__Host-SESSION', $config->name);
        self::assertSame('/', $config->cookiePath);
    }

    public function testTheRulesAlsoApplyToDirectConstruction(): void
    {
        $this->expectException(ConfigurationException::class);

        new SessionConfig('__Host-SESSION', 0, true, true, 'Lax', '/app');
    }

    // -------------------------------------------------------------------------
    // Explicit camelCase keys
    // -------------------------------------------------------------------------

    public function testAcceptsCamelCaseKeys(): void
    {
        $config = SessionConfig::fromArray([
            'name'       => 'APP',
            'lifetime'   => 3600,
            'httpOnly'   => false,
            'secure'     => true,
            'sameSite'   => 'Strict',
            'cookiePath' => '/app',
        ]);

        self::assertSame('APP',     $config->name);
        self::assertSame(3600,      $config->lifetime);
        self::assertFalse($config->httpOnly);
        self::assertTrue($config->secure);
        self::assertSame('Strict',  $config->sameSite);
        self::assertSame('/app',    $config->cookiePath);
    }

    // -------------------------------------------------------------------------
    // snake_case key variants
    // -------------------------------------------------------------------------

    public function testAcceptsSnakeCaseKeys(): void
    {
        $config = SessionConfig::fromArray([
            'http_only'   => false,
            'same_site'   => 'None',
            'cookie_path' => '/api',
        ]);

        self::assertFalse($config->httpOnly);
        self::assertSame('None',  $config->sameSite);
        self::assertSame('/api',  $config->cookiePath);
    }

    // -------------------------------------------------------------------------
    // sameSite valid values
    // -------------------------------------------------------------------------

    #[DataProvider('sameSiteProvider')]
    public function testAcceptsValidSameSiteValues(string $value): void
    {
        $config = SessionConfig::fromArray(['sameSite' => $value]);

        self::assertSame($value, $config->sameSite);
    }

    /** @return array<string, array{string}> */
    public static function sameSiteProvider(): array
    {
        return [
            'Strict' => ['Strict'],
            'Lax'    => ['Lax'],
            'None'   => ['None'],
        ];
    }

    // -------------------------------------------------------------------------
    // Validation failures
    // -------------------------------------------------------------------------

    public function testThrowsForInvalidSameSite(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sameSite');

        SessionConfig::fromArray(['sameSite' => 'Loose']);
    }

    public function testThrowsForEmptyName(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('name');

        SessionConfig::fromArray(['name' => '  ']);
    }

    public function testThrowsForNegativeLifetime(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('lifetime');

        SessionConfig::fromArray(['lifetime' => -1]);
    }

    // -------------------------------------------------------------------------
    // idleTimeout
    // -------------------------------------------------------------------------

    public function testIdleTimeoutIsUnsetByDefault(): void
    {
        self::assertNull(SessionConfig::fromArray([])->idleTimeout);
        self::assertNull((new SessionConfig('APP', 0, true, false, 'Lax', '/'))->idleTimeout);
    }

    /** @return array<string, array{array<string, mixed>}> */
    public static function idleTimeoutKeyProvider(): array
    {
        return [
            'camelCase'        => [['idleTimeout' => 1800]],
            'snake_case'       => [['idle_timeout' => 1800]],
            'numeric string'   => [['idle_timeout' => '1800']],
        ];
    }

    /** @param array<string, mixed> $values */
    #[DataProvider('idleTimeoutKeyProvider')]
    public function testAcceptsIdleTimeoutInEitherKeyStyle(array $values): void
    {
        self::assertSame(1800, SessionConfig::fromArray($values)->idleTimeout);
    }

    /** @return array<string, array{mixed, string}> */
    public static function invalidIdleTimeoutProvider(): array
    {
        return [
            'zero'              => [0, '0'],
            'zero string'       => ['0', '"0"'],
            'negative'          => [-60, '-60'],
            'negative string'   => ['-60', '"-60"'],
            'unit suffix'       => ['30m', '"30m"'],
            'decimal string'    => ['1.9', '"1.9"'],
            'float'             => [1.9, '1.9'],
            'boolean'           => [true, 'true'],
            'word'              => ['abc', '"abc"'],
            'empty string'      => ['', '""'],
            'padded'            => [' 1800', '" 1800"'],
            'all zeros'         => ['000', '"000"'],
            'array'             => [[1800], 'array'],
        ];
    }

    #[DataProvider('invalidIdleTimeoutProvider')]
    public function testThrowsForAnIdleTimeoutThatIsNotAPositiveWholeNumber(mixed $value, string $shown): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'idleTimeout' has invalid value {$shown}: ");

        SessionConfig::fromArray(['idle_timeout' => $value]);
    }

    public function testAcceptsAZeroPaddedIdleTimeoutAsDecimalSeconds(): void
    {
        self::assertSame(600, SessionConfig::fromArray(['idle_timeout' => '0600'])->idleTimeout);
    }

    public function testAnIdleTimeoutBeyondTheIntegerRangeIsReportedAsTooLarge(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('"99999999999999999999": is too large');

        SessionConfig::fromArray(['idle_timeout' => '99999999999999999999']);
    }

    /** @return array<string, array{mixed}> */
    public static function idleTimeoutOverflowingTheExpiryColumnProvider(): array
    {
        return [
            'one past the limit' => [2_147_483_648 - time()],
            'integer max'        => [2_147_483_647],
            'string'             => ['2147483647'],
            'php max'            => [PHP_INT_MAX],
        ];
    }

    /** The documented schema stores the expiry, now plus the timeout, in a 32-bit INTEGER column. */
    #[DataProvider('idleTimeoutOverflowingTheExpiryColumnProvider')]
    public function testRefusesAnIdleTimeoutWhoseExpiryOverflowsTheIntegerColumn(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('INTEGER');

        SessionConfig::fromArray(['idle_timeout' => $value]);
    }

    public function testAcceptsAnIdleTimeoutJustShortOfTheExpiryColumnLimit(): void
    {
        $seconds = 2_147_483_647 - time() - 60;

        self::assertSame($seconds, SessionConfig::fromArray(['idle_timeout' => $seconds])->idleTimeout);
    }

    public function testDirectConstructionAlsoRefusesAnIdleTimeoutThatOverflowsTheExpiryColumn(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/invalid value \\d+: must be at most \\d+ seconds [^:]*INTEGER[^:]*\\.\\z/");

        new SessionConfig('APP', 0, true, false, 'Lax', '/', idleTimeout: PHP_INT_MAX);
    }

    public function testDirectConstructionAlsoRefusesANonPositiveIdleTimeout(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('idleTimeout');

        new SessionConfig('APP', 0, true, false, 'Lax', '/', idleTimeout: 0);
    }

    // -------------------------------------------------------------------------
    // Boolean flags are read strictly
    // -------------------------------------------------------------------------

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unreadableBooleans(): array
    {
        return [
            'empty string' => [''],
            'whitespace only' => ['   '],
            'unknown word' => ['maybe'],
            'int 2' => [2],
        ];
    }

    #[DataProvider('unreadableBooleans')]
    public function testAnUnreadableSecureValueIsRefusedInsteadOfReadAsInsecure(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);

        SessionConfig::fromArray(['secure' => $value]);
    }

    #[DataProvider('unreadableBooleans')]
    public function testAnUnreadableHttpOnlyValueIsRefusedInsteadOfReadAsDisabled(mixed $value): void
    {
        $this->expectException(ConfigurationException::class);

        SessionConfig::fromArray(['httpOnly' => $value]);
    }

    public function testSecureOffReadsAsInsecureNotAsSecure(): void
    {
        $config = SessionConfig::fromArray(['secure' => 'off']);

        self::assertFalse($config->secureAuto);
        self::assertFalse($config->resolveSecure(true));
    }

    public function testHttpOnlyOffReadsAsDisabled(): void
    {
        $config = SessionConfig::fromArray(['httpOnly' => 'off']);

        self::assertFalse($config->httpOnly);
    }

    public function testHttpOnlyFalseReadsAsDisabledThroughSnakeCaseKey(): void
    {
        $config = SessionConfig::fromArray(['http_only' => 'false']);

        self::assertFalse($config->httpOnly);
    }

    public function testAnUnreadableSnakeCaseHttpOnlyIsRefusedNamingTheKeyWritten(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'http_only' has invalid value");

        SessionConfig::fromArray(['http_only' => 'maybe']);
    }

    public function testAnUnreadableCamelCaseHttpOnlyIsRefusedNamingTheKeyWritten(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("field 'httpOnly' has invalid value");

        SessionConfig::fromArray(['httpOnly' => 'maybe']);
    }

    public function testTheHostPrefixPathRefusalDoesNotRepeatTheName(): void
    {
        try {
            SessionConfig::fromArray(['name' => "__Host-s\x1b[2K", 'cookiePath' => '/app', 'secure' => true]);
            self::fail('A __Host- cookie on another path must be refused.');
        } catch (ConfigurationException $e) {
            self::assertSame(
                "Configuration section 'session' field 'cookiePath' has invalid value \"/app\": must be \"/\" when the "
                . 'session name has the __Host- prefix.',
                $e->getMessage(),
            );
        }
    }

    public function testAMisspelledSecureIsRefusedRatherThanLeavingTheCookieWithoutSecure(): void
    {
        try {
            SessionConfig::fromArray(['secur' => true]);

            self::fail('A misspelled secure was accepted.');
        } catch (ConfigurationException $exception) {
            self::assertSame(
                "Configuration section 'session' field 'secur' is an unknown key: did you mean \"secure\"?",
                $exception->getMessage(),
            );
        }
    }

    public function testTwoSpellingsOfOneSettingAreRefused(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("Configuration section 'session' sets both 'sameSite' and 'same_site': keep one.");

        SessionConfig::fromArray(['sameSite' => 'Strict', 'same_site' => 'Lax']);
    }
}
