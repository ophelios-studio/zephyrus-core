<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;

/**
 * Reads that only PostgreSQL can answer honestly: a real boolean column, and
 * real array types whose text form is what the driver hands back.
 *
 * Skipped unless ZEPHYRUS_TEST_PGSQL_DSN holds a full pdo_pgsql DSN with its
 * credentials, for example pgsql:host=127.0.0.1;port=5432;dbname=test;user=u;password=p.
 * Nothing is created or dropped: every statement is a literal SELECT.
 */
final class DatabasePostgresReadTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('ZEPHYRUS_TEST_PGSQL_DSN is not set.');
        }

        $this->db = new Database(new PDO($dsn));
    }

    public function testSelectValueReturnsAFalseBooleanColumnInsteadOfTheDefault(): void
    {
        self::assertFalse($this->db->selectValue('SELECT false', [], true));
    }

    public function testSelectValueReturnsANullColumnInsteadOfTheDefault(): void
    {
        self::assertNull($this->db->selectValue('SELECT NULL::text', [], 'default'));
    }

    public function testSelectBoolReadsAFalseBooleanColumn(): void
    {
        self::assertFalse($this->db->selectBool('SELECT false', [], true));
    }

    public function testTextArrayColumnKeepsCommasQuotesAndNullsInsideElements(): void
    {
        $rows = $this->db->select(
            "SELECT ARRAY['Rue du Pont, app. 4', 'Firmin \"Témoin\"', 'NULL', NULL]::text[] AS v",
        );

        self::assertSame(['Rue du Pont, app. 4', 'Firmin "Témoin"', 'NULL', null], $rows[0]->v);
    }

    public function testEmptyTextArrayColumnDecodesToAnEmptyList(): void
    {
        $rows = $this->db->select("SELECT '{}'::text[] AS v");

        self::assertSame([], $rows[0]->v);
    }
}
