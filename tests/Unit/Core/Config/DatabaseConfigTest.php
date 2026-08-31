<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\ConfigurationException;
use Zephyrus\Core\Config\DatabaseConfig;

final class DatabaseConfigTest extends TestCase
{
    // -------------------------------------------------------------------------
    // Defaults
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Explicit values
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Port boundary validation
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Required field validation
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // Charset validation
    // -------------------------------------------------------------------------

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
     * REGRESSION. charset is interpolated verbatim into
     * `SET client_encoding TO '<charset>'` at connect time (Database::fromConfig),
     * where a semicolon would try to open a second command. fromArray() validated
     * it; the constructor did not, so an object built directly reached that string
     * unchecked. Native prepares now refuse a second statement outright, which is
     * the layer client-side emulation used to give away, but the check stays: that
     * refusal is the driver's rather than ours. Guarded like sslMode and
     * sslRootCert already are.
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

    // -------------------------------------------------------------------------
    // Driver validation
    // -------------------------------------------------------------------------

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

    // -------------------------------------------------------------------------
    // emulate_prepares: REMOVED, and rejected loudly rather than ignored
    // -------------------------------------------------------------------------

    /**
     * The setting used to turn PDO::ATTR_EMULATE_PREPARES on. It was removed
     * because it is a security downgrade, and a file that still carries it must
     * FAIL rather than boot: the operator who wrote the line believed something
     * about their deployment that is no longer true, and a silently dropped key
     * leaves that belief in place.
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
     * Rejected even at false, which looks pedantic and is not. `false` was the
     * safe value, so a file carrying it is a file whose author considered the
     * question; leaving the key readable would keep documenting a knob that no
     * longer exists, and the next person to flip it to true would get silence.
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
     * The message has to be actionable on its own: an operator reading a boot
     * failure gets the key, why it is gone, and what to type.
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
     * The capability is gone from the value object too, not just from the file
     * format: nothing downstream can read an emulation preference off a config.
     */
    public function testDatabaseConfigNoLongerCarriesAnEmulatePreparesProperty(): void
    {
        self::assertFalse(
            property_exists(DatabaseConfig::class, 'emulatePrepares'),
            'DatabaseConfig must not expose an emulatePrepares property.',
        );
    }

    // -------------------------------------------------------------------------
    // sslMode / sslRootCert (opt-in libpq TLS policy)
    // -------------------------------------------------------------------------

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
        // Environment variables arrive with stray whitespace and shouted
        // spellings; libpq matches the value exactly, so normalise rather than
        // hand it a string it would reject at connect time.
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'sslmode'  => '  Verify-Full ',
        ]);

        self::assertSame('verify-full', $config->sslMode);
    }

    public function testBlankSslModeCollapsesToNull(): void
    {
        // A set-but-empty environment variable means "not configured", exactly
        // like an absent one, and must leave the DSN untouched.
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
        // The value is interpolated into the DSN verbatim, so the guarantee has
        // to hold for a direct caller too, not only for fromArray().
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
        // Deliberate: libpq falls back to ~/.postgresql/root.crt and reports a
        // precise error when no anchor exists, so rejecting this here would
        // refuse a configuration PostgreSQL itself accepts.
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
