<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Binary;
use Zephyrus\Data\Database;

/**
 * A Database built by fromConfig() sends every query as an unnamed statement with server-side bound parameters.
 *
 * Skipped unless ZEPHYRUS_TEST_PGSQL_DSN holds a full pdo_pgsql DSN with its credentials.
 */
final class DatabasePostgresUnnamedStatementsTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');

        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped('ZEPHYRUS_TEST_PGSQL_DSN is not set.');
        }

        $parts = [];
        foreach (explode(';', substr($dsn, strlen('pgsql:'))) as $pair) {
            [$key, $value] = explode('=', $pair, 2);
            $parts[$key] = $value;
        }

        $this->db = Database::fromConfig(DatabaseConfig::fromArray([
            'host' => $parts['host'],
            'port' => (int) $parts['port'],
            'database' => $parts['dbname'],
            'username' => $parts['user'],
            'password' => $parts['password'],
            'sslMode' => 'disable',
        ]));
        $this->db->pdo()->exec('CREATE TEMP TABLE t (id INTEGER PRIMARY KEY, flag BOOLEAN, payload BYTEA, label TEXT)');
    }

    public function testAParameterisedQueryLeavesNoNamedPreparedStatementOnTheServer(): void
    {
        $held = $this->db->query('SELECT ?::int AS a', [5]);

        $count = $this->db->selectValue('SELECT count(*) FROM pg_prepared_statements');

        self::assertSame(5, $held->fetch()->a);
        self::assertSame(0, $count);
    }

    public function testBooleanNullAndBinaryParametersRoundTripPositionalAndNamed(): void
    {
        $bytes = "\x00\x01\xff'\\";
        $this->db->execute('INSERT INTO t (id, flag, payload, label) VALUES (?, ?, ?, ?)', [1, false, new Binary($bytes), null]);
        $this->db->execute(
            'INSERT INTO t (id, flag, payload, label) VALUES (:id, :flag, :payload, :label)',
            ['id' => 2, 'flag' => true, 'payload' => new Binary($bytes), 'label' => null],
        );

        $rows = $this->db->select('SELECT id, flag, payload, label FROM t ORDER BY id');

        self::assertFalse($rows[0]->flag);
        self::assertTrue($rows[1]->flag);
        self::assertNull($rows[0]->label);
        self::assertNull($rows[1]->label);
        self::assertSame($bytes, self::bytes($rows[0]->payload));
        self::assertSame($bytes, self::bytes($rows[1]->payload));
    }

    private static function bytes(mixed $payload): string
    {
        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }

        self::assertIsString($payload);

        return $payload;
    }
}
