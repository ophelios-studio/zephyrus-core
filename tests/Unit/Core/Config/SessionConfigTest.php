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

    /**
     * SessionConfig::fromArray([]) used to emit
     * "PHPSESSID=...; path=/; HttpOnly; SameSite=Lax" with no Secure, so an
     * HTTPS deployment that did not set the flag itself shipped a session
     * cookie a downgraded request could carry. The default is now "auto", the
     * same one Symfony ships.
     */
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

    /** Positional construction keeps the exact behaviour it had: no auto. */
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
        // "auto" is correct on the HTTPS origin the setting is for.
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

    /**
     * The rules live in the constructor, not only in fromArray(), because
     * building the object directly is what the framework's own middlewares do.
     */
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
}
