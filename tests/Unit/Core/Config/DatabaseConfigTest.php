<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\DatabaseConfig;

final class DatabaseConfigTest extends TestCase
{
    public function testBuildsWithDefaults(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'zephyrus',
            'username' => 'molt',
        ]);

        self::assertSame('pgsql',     $config->driver);
        self::assertSame('localhost',  $config->host);
        self::assertSame(5432,        $config->port);
        self::assertSame('utf8',      $config->charset);
        self::assertSame('',          $config->password);
    }

    public function testBuildsWithExplicitValues(): void
    {
        $config = DatabaseConfig::fromArray([
            'host'     => 'db.internal',
            'port'     => 5432,
            'database' => 'myapp',
            'username' => 'admin',
            'password' => 's3cr3t',
            'charset'  => 'latin1',
        ]);

        self::assertSame('db.internal', $config->host);
        self::assertSame(5432,          $config->port);
        self::assertSame('myapp',       $config->database);
        self::assertSame('admin',       $config->username);
        self::assertSame('s3cr3t',      $config->password);
        self::assertSame('latin1',      $config->charset);
    }

    public function testPortLowerBoundary(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'port'     => 1,
        ]);

        self::assertSame(1, $config->port);
    }

    public function testPortUpperBoundary(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'port'     => 65535,
        ]);

        self::assertSame(65535, $config->port);
    }

    public function testThrowsForPortZero(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('port');

        DatabaseConfig::fromArray(['database' => 'db', 'username' => 'u', 'port' => 0]);
    }

    public function testThrowsForPortAboveMax(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('port');

        DatabaseConfig::fromArray(['database' => 'db', 'username' => 'u', 'port' => 65536]);
    }

    public function testThrowsForMissingDatabase(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'database'");

        DatabaseConfig::fromArray(['username' => 'molt']);
    }

    public function testThrowsForMissingUsername(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage("'username'");

        DatabaseConfig::fromArray(['database' => 'mydb']);
    }

    public function testThrowsForEmptyDatabase(): void
    {
        $this->expectException(ConfigurationException::class);

        DatabaseConfig::fromArray(['database' => '  ', 'username' => 'u']);
    }

    public function testThrowsForEmptyUsername(): void
    {
        $this->expectException(ConfigurationException::class);

        DatabaseConfig::fromArray(['database' => 'db', 'username' => '']);
    }

    public function testThrowsForInvalidCharset(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('charset');

        DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'charset'  => 'utf8; DROP TABLE users',
        ]);
    }

    /**
     * The constructor validates charset as fromArray() does: it is interpolated
     * into a SET statement at connect time.
     */
    public function testThrowsForCharsetSmuggledThroughTheConstructor(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('charset');

        new DatabaseConfig(
            driver: 'pgsql',
            host: 'localhost',
            port: 5432,
            database: 'db',
            username: 'u',
            password: '',
            charset: "utf8'; CREATE TABLE pwned (x int); SET client_encoding TO 'utf8",
        );
    }

    public function testConstructorStillAcceptsAnOrdinaryCharset(): void
    {
        $config = new DatabaseConfig(
            driver: 'pgsql',
            host: 'localhost',
            port: 5432,
            database: 'db',
            username: 'u',
            password: '',
            charset: 'utf8mb4',
        );

        self::assertSame('utf8mb4', $config->charset);
    }

    public function testThrowsForUnsupportedDriver(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('driver');

        DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'driver'   => 'mysql',
        ]);
    }

    public function testDefaultDriverIsPgsql(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
        ]);

        self::assertSame('pgsql', $config->driver);
    }

    /**
     * The removed setting turned PDO::ATTR_EMULATE_PREPARES on, a security downgrade.
     * A file still carrying it must fail rather than boot without it.
     */
    public function testFromArrayRejectsTheRemovedSnakeCaseEmulatePreparesKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('emulate_prepares');

        DatabaseConfig::fromArray([
            'database'         => 'db',
            'username'         => 'u',
            'emulate_prepares' => true,
        ]);
    }

    public function testFromArrayRejectsTheRemovedCamelCaseEmulatePreparesKey(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('emulatePrepares');

        DatabaseConfig::fromArray([
            'database'        => 'db',
            'username'        => 'u',
            'emulatePrepares' => true,
        ]);
    }

    /**
     * Rejected even at false: the key no longer exists, so a stale file must fail too.
     */
    public function testFromArrayRejectsTheRemovedKeyEvenWhenSetToFalse(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('emulate_prepares');

        DatabaseConfig::fromArray([
            'database'         => 'db',
            'username'         => 'u',
            'emulate_prepares' => false,
        ]);
    }

    /**
     * The message names the key, why it was removed and what to write instead.
     */
    public function testTheRejectionNamesTheReasonAndTheRemedy(): void
    {
        try {
            DatabaseConfig::fromArray([
                'database'         => 'db',
                'username'         => 'u',
                'emulate_prepares' => true,
            ]);
            self::fail('expected the removed key to be rejected');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('REMOVED', $e->getMessage());
            self::assertStringContainsString('emulate_prepares', $e->getMessage());
            self::assertStringContainsString('delete this line', $e->getMessage());
        }
    }

    /**
     * The property is gone from the value object too, not only from the file format.
     */
    public function testDatabaseConfigNoLongerCarriesAnEmulatePreparesProperty(): void
    {
        self::assertFalse(
            property_exists(DatabaseConfig::class, 'emulatePrepares'),
            'DatabaseConfig must not expose an emulatePrepares property.',
        );
    }

    public function testSslModeDefaultsToNull(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
        ]);

        self::assertNull($config->sslMode);
        self::assertNull($config->sslRootCert);
    }

    public function testEverySupportedSslModeIsAccepted(): void
    {
        foreach (DatabaseConfig::SSL_MODES as $mode) {
            $config = DatabaseConfig::fromArray([
                'database' => 'db',
                'username' => 'u',
                'sslmode'  => $mode,
            ]);

            self::assertSame($mode, $config->sslMode);
        }
    }

    public function testSslModeCamelCaseKeyIsAccepted(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'sslMode'  => 'require',
        ]);

        self::assertSame('require', $config->sslMode);
    }

    public function testSslModeIsTrimmedAndCaseFolded(): void
    {
        // libpq matches sslmode exactly, so the value is normalized before it reaches the DSN.
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'sslmode'  => '  Verify-Full ',
        ]);

        self::assertSame('verify-full', $config->sslMode);
    }

    public function testBlankSslModeCollapsesToNull(): void
    {
        // A set-but-empty value counts as unset and must leave the DSN untouched.
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'sslmode'  => '   ',
        ]);

        self::assertNull($config->sslMode);
    }

    public function testThrowsForUnknownSslMode(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sslmode');

        DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'sslmode'  => 'required',
        ]);
    }

    public function testUnknownSslModeErrorListsTheSupportedValues(): void
    {
        try {
            DatabaseConfig::fromArray([
                'database' => 'db',
                'username' => 'u',
                'sslmode'  => 'on',
            ]);
            self::fail('An unrecognised sslmode must not be accepted.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString('verify-full', $e->getMessage());
            self::assertStringContainsString('disable', $e->getMessage());
        }
    }

    public function testThrowsForSslModeSmuggledThroughTheConstructor(): void
    {
        // The value goes into the DSN verbatim, so the constructor validates it too.
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sslmode');

        new DatabaseConfig(
            driver: 'pgsql',
            host: 'localhost',
            port: 5432,
            database: 'db',
            username: 'u',
            password: '',
            charset: 'utf8',
            sslMode: 'require;dbname=other',
        );
    }

    public function testSslRootCertIsKeptVerbatimAndCaseSensitive(): void
    {
        $config = DatabaseConfig::fromArray([
            'database'    => 'db',
            'username'    => 'u',
            'sslmode'     => 'verify-ca',
            'sslrootcert' => ' /etc/ssl/certs/PG-Root.crt ',
        ]);

        self::assertSame('/etc/ssl/certs/PG-Root.crt', $config->sslRootCert);
    }

    public function testSslRootCertCamelCaseKeyIsAccepted(): void
    {
        $config = DatabaseConfig::fromArray([
            'database'    => 'db',
            'username'    => 'u',
            'sslRootCert' => '/etc/ssl/certs/pg-root.crt',
        ]);

        self::assertSame('/etc/ssl/certs/pg-root.crt', $config->sslRootCert);
    }

    public function testVerifyModeWithoutRootCertIsAccepted(): void
    {
        // Deliberate: libpq falls back to ~/.postgresql/root.crt and reports a missing anchor itself.
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'sslmode'  => 'verify-full',
        ]);

        self::assertSame('verify-full', $config->sslMode);
        self::assertNull($config->sslRootCert);
    }

    public function testThrowsForSslRootCertThatWouldBreakTheDsn(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sslrootcert');

        DatabaseConfig::fromArray([
            'database'    => 'db',
            'username'    => 'u',
            'sslrootcert' => '/etc/root.crt;sslmode=disable',
        ]);
    }
}
