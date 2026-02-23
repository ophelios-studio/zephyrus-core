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
