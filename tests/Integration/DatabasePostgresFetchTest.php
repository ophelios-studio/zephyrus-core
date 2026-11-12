<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;

/**
 * Statements outside the cached allow-list (FETCH, EXECUTE) must be described on every call.
 *
 * Skipped unless ZEPHYRUS_TEST_PGSQL_DSN holds a full pdo_pgsql DSN with its credentials.
 */
final class DatabasePostgresFetchTest extends TestCase
{
    private PDO $pdo;

    private Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');

        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('ZEPHYRUS_TEST_PGSQL_DSN is not set.');
        }

        $this->pdo = new PDO($dsn);
        $this->db = new Database($this->pdo);
        $this->pdo->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->pdo) && $this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function fetchStatements(): iterable
    {
        yield 'plain' => ['FETCH 10 FROM c'];
        yield 'lowercase' => ['fetch 10 from c'];
        yield 'line comment' => ["-- next batch\nFETCH 10 FROM c"];
        yield 'block comment' => ['/* next batch */ FETCH 10 FROM c'];
        yield 'mixed comments and blanks' => ["  \n-- a\n/* b */\n\tFetch 10 FROM c"];
        yield 'nested block comment' => ['/* a /* b */ c */ FETCH 10 FROM c'];
        yield 'nested block comment hiding a keyword' => ['/* a /* b */ select */ FETCH 10 FROM c'];
        yield 'nested block comment holding a replaced query' => ["/* replaced:\n/* v1 */ SELECT * FROM report\n*/\nFETCH 1 FROM c"];
        yield 'line comment ended by a lone carriage return' => ["-- x\rFETCH 10 FROM c"];
        yield 'leading semicolon' => [';FETCH 10 FROM c'];
    }

    #[DataProvider('fetchStatements')]
    public function testASecondCursorUnderTheSameNameKeepsItsOwnColumnTypes(string $fetch): void
    {
        $this->openCursor("SELECT 1 AS id, 'x'::text AS v");
        $first = $this->db->select($fetch);
        $this->db->query('CLOSE c');

        $this->openCursor('SELECT 2 AS id, \'{"k":1}\'::jsonb AS v');
        $second = $this->db->select($fetch);

        self::assertSame('x', $first[0]->v);
        self::assertInstanceOf(\stdClass::class, $second[0]->v);
        self::assertSame(1, $second[0]->v->k);
    }

    public function testSelectOneOnASecondCursorKeepsItsOwnColumnTypes(): void
    {
        $this->openCursor("SELECT 1 AS id, 'x'::text AS v");
        $this->db->selectOne('FETCH 1 FROM c');
        $this->db->query('CLOSE c');

        $this->openCursor('SELECT 2 AS id, \'{"k":1}\'::jsonb AS v');
        $row = $this->db->selectOne('FETCH 1 FROM c');

        self::assertNotNull($row);
        self::assertInstanceOf(\stdClass::class, $row->v);
    }

    public function testAFetchPrecededByManyCommentLinesKeepsItsOwnColumnTypes(): void
    {
        $fetch = str_repeat("  -- pad\n", 10000) . 'FETCH 10 FROM c';

        $this->openCursor("SELECT 1 AS id, 'x'::text AS v");
        $first = $this->db->select($fetch);
        $this->db->query('CLOSE c');

        $this->openCursor('SELECT 2 AS id, \'{"k":1}\'::jsonb AS v');
        $second = $this->db->select($fetch);

        self::assertSame('x', $first[0]->v);
        self::assertInstanceOf(\stdClass::class, $second[0]->v);
    }

    public function testAnExecutedPreparedStatementIsDescribedOnEveryCall(): void
    {
        $this->db->query("PREPARE p AS SELECT 1 AS id, 'x'::text AS v");
        $first = $this->db->select('EXECUTE p');
        $this->db->query('DEALLOCATE p');

        $this->db->query('PREPARE p AS SELECT 2 AS id, \'{"k":1}\'::jsonb AS v');
        $second = $this->db->select('EXECUTE p');

        self::assertSame('x', $first[0]->v);
        self::assertInstanceOf(\stdClass::class, $second[0]->v);
    }

    private function openCursor(string $select): void
    {
        $this->db->query('DECLARE c CURSOR FOR ' . $select);
    }
}
