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

        self::assertSame('localhost', $config->host);
        self::assertSame(3306,       $config->port);
        self::assertSame('utf8mb4',  $config->charset);
        self::assertSame('',         $config->password);
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
}
