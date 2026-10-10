<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Core\Config;

use PHPUnit\Framework\Attributes\DataProvider;
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

    public function testThrowsForSslRootCertContainingABackslash(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sslrootcert');

        DatabaseConfig::fromArray([
            'database'    => 'db',
            'username'    => 'u',
            'sslrootcert' => 'C:\\certs\\root.crt',
        ]);
    }

    public function testThrowsForSslRootCertContainingABackslashThroughTheConstructor(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sslrootcert');

        new DatabaseConfig('pgsql', 'localhost', 5432, 'db', 'u', '', 'utf8', null, '/etc/ssl/a\\b.crt');
    }

    public function testColumnCacheVersionDefaultsToEmptyString(): void
    {
        $config = DatabaseConfig::fromArray(['database' => 'db', 'username' => 'u']);

        self::assertSame('', $config->columnCacheVersion);
    }

    public function testColumnCacheVersionCamelCaseKeyIsAccepted(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'columnCacheVersion' => '2026.10.1',
        ]);

        self::assertSame('2026.10.1', $config->columnCacheVersion);
    }

    public function testColumnCacheVersionSnakeCaseKeyIsAccepted(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'column_cache_version' => '42',
        ]);

        self::assertSame('42', $config->columnCacheVersion);
    }

    public function testColumnCacheVersionIsTrimmedAndBlankMeansEmpty(): void
    {
        $padded = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'columnCacheVersion' => '  release-7 ',
        ]);
        $blank = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'columnCacheVersion' => '   ',
        ]);

        self::assertSame('release-7', $padded->columnCacheVersion);
        self::assertSame('', $blank->columnCacheVersion);
    }

    public function testIntegerAndQuotedColumnCacheVersionsKeepTheirDigits(): void
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'columnCacheVersion' => 12,
        ]);

        $dotted = DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'columnCacheVersion' => '1.10',
        ]);

        self::assertSame('12', $config->columnCacheVersion);
        self::assertSame('1.10', $dotted->columnCacheVersion);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonScalarColumnCacheVersions(): iterable
    {
        yield 'list' => [['v1']];
        yield 'map' => [['version' => 'v1']];
        yield 'object' => [new \stdClass()];
    }

    #[DataProvider('nonScalarColumnCacheVersions')]
    public function testThrowsForNonScalarColumnCacheVersion(mixed $version): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/columnCacheVersion' has invalid value '(array|stdClass)'/");

        DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'columnCacheVersion' => $version,
        ]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function unquotedScalarColumnCacheVersions(): iterable
    {
        yield 'float' => [1.10];
        yield 'true' => [true];
        yield 'false' => [false];
    }

    #[DataProvider('unquotedScalarColumnCacheVersions')]
    public function testThrowsForFloatOrBooleanColumnCacheVersionAndAsksForQuotes(mixed $version): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/columnCacheVersion' has invalid value '(float|bool)': .*quote/");

        DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'columnCacheVersion' => $version,
        ]);
    }

    public function testThrowsForSslModeThatIsAnArray(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/sslMode' has invalid value 'array'/");

        DatabaseConfig::fromArray([
            'database' => 'db',
            'username' => 'u',
            'sslMode'  => ['require'],
        ]);
    }

    public function testThrowsForSslRootCertThatIsAnArray(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessageMatches("/sslRootCert' has invalid value 'array'/");

        DatabaseConfig::fromArray([
            'database'    => 'db',
            'username'    => 'u',
            'sslRootCert' => ['/etc/ssl/root.crt'],
        ]);
    }

    public function testSnakeCaseSslSettingsAreAccepted(): void
    {
        $config = DatabaseConfig::fromArray([
            'database'      => 'db',
            'username'      => 'u',
            'ssl_mode'      => 'verify-full',
            'ssl_root_cert' => '/etc/ssl/root.crt',
        ]);

        self::assertSame('verify-full', $config->sslMode);
        self::assertSame('/etc/ssl/root.crt', $config->sslRootCert);
    }

    public function testCamelCaseSslSettingsWinOverSnakeCase(): void
    {
        $config = DatabaseConfig::fromArray([
            'database'      => 'db',
            'username'      => 'u',
            'sslMode'       => 'require',
            'ssl_mode'      => 'disable',
            'sslRootCert'   => '/a.crt',
            'ssl_root_cert' => '/b.crt',
        ]);

        self::assertSame('require', $config->sslMode);
        self::assertSame('/a.crt', $config->sslRootCert);
    }

    public function testThrowsForCharsetEndingInANewline(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('charset');

        DatabaseConfig::fromArray(['database' => 'db', 'username' => 'u', 'charset' => "utf8\n"]);
    }

    public function testThrowsForCharsetEndingInANewlineThroughTheConstructor(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('charset');

        new DatabaseConfig('pgsql', 'localhost', 5432, 'db', 'u', '', "utf8\n");
    }

    public function testThrowsForSslRootCertEndingInANewlineThroughTheConstructor(): void
    {
        $this->expectException(ConfigurationException::class);
        $this->expectExceptionMessage('sslrootcert');

        new DatabaseConfig('pgsql', 'localhost', 5432, 'db', 'u', '', 'utf8', null, "/etc/ssl/root.crt\n");
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function optionalSettingSpellings(): iterable
    {
        yield 'sslMode' => ['sslMode', 'sslMode'];
        yield 'sslmode' => ['sslmode', 'sslmode'];
        yield 'ssl_mode' => ['ssl_mode', 'ssl_mode'];
        yield 'sslRootCert' => ['sslRootCert', 'sslRootCert'];
        yield 'sslrootcert' => ['sslrootcert', 'sslrootcert'];
        yield 'ssl_root_cert' => ['ssl_root_cert', 'ssl_root_cert'];
        yield 'columnCacheVersion' => ['columnCacheVersion', 'columnCacheVersion'];
        yield 'column_cache_version' => ['column_cache_version', 'column_cache_version'];
    }

    #[DataProvider('optionalSettingSpellings')]
    public function testRefusalNamesTheKeyAsWritten(string $key, string $expected): void
    {
        try {
            DatabaseConfig::fromArray(['database' => 'db', 'username' => 'u', $key => false]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("field '" . $expected . "' has invalid value", $e->getMessage());
        }
    }

    public function testQuoteHintIsOnlyShownForColumnCacheVersion(): void
    {
        foreach (['sslMode', 'ssl_root_cert'] as $key) {
            try {
                DatabaseConfig::fromArray(['database' => 'db', 'username' => 'u', $key => 1.5]);
                self::fail('Expected a ConfigurationException.');
            } catch (ConfigurationException $e) {
                self::assertStringNotContainsString('1.10', $e->getMessage());
            }
        }

        try {
            DatabaseConfig::fromArray(['database' => 'db', 'username' => 'u', 'columnCacheVersion' => 1.5]);
            self::fail('Expected a ConfigurationException.');
        } catch (ConfigurationException $e) {
            self::assertStringContainsString("'1.10'", $e->getMessage());
        }
    }
}
