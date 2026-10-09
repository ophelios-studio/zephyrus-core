<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;

/**
 * Generated ids on a real PostgreSQL server, where the session's last sequence
 * value can belong to a table an AFTER INSERT trigger wrote into.
 *
 * Runs only when ZEPHYRUS_TEST_PGSQL_DSN holds a PDO DSN carrying its own
 * credentials. Each test works in a throwaway schema it drops afterwards.
 */
final class DatabaseInsertIdPostgresTest extends TestCase
{
    private string $schema;
    private PDO $admin;
    private Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(
                'Set ZEPHYRUS_TEST_PGSQL_DSN (e.g. pgsql:host=127.0.0.1;port=5432;dbname=postgres;user=postgres;password=postgres) to run the PostgreSQL id tests.',
            );
        }

        $this->schema = 'zephyrus_id_test_' . bin2hex(random_bytes(6));
        $this->admin = new PDO($dsn);
        $this->admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->admin->exec("CREATE SCHEMA {$this->schema}");
        $this->admin->exec("CREATE TABLE {$this->schema}.items (id SERIAL PRIMARY KEY, label TEXT NOT NULL UNIQUE)");
        $this->admin->exec("CREATE TABLE {$this->schema}.audit (id SERIAL PRIMARY KEY, item_id INTEGER NOT NULL)");
        $this->admin->exec("SELECT setval('{$this->schema}.audit_id_seq', 900)");
        $this->admin->exec(
            "CREATE FUNCTION {$this->schema}.audit_item() RETURNS trigger LANGUAGE plpgsql AS "
            . "'BEGIN INSERT INTO {$this->schema}.audit (item_id) VALUES (NEW.id); RETURN NULL; END'",
        );
        $this->admin->exec(
            "CREATE TRIGGER items_audit AFTER INSERT ON {$this->schema}.items "
            . "FOR EACH ROW EXECUTE FUNCTION {$this->schema}.audit_item()",
        );

        $this->db = new Database(new PDO($dsn));
        $this->db->pdo()->exec("SET search_path TO {$this->schema}");
    }

    protected function tearDown(): void
    {
        if (!isset($this->admin)) {
            return;
        }

        unset($this->db);
        $this->admin->exec("DROP SCHEMA IF EXISTS {$this->schema} CASCADE");
    }

    public function testReturningIdIsTheItemsEvenWhenATriggerAdvancedAnotherSequence(): void
    {
        $id = $this->db->insertGetId('INSERT INTO items (label) VALUES (?) RETURNING id', ['first']);

        self::assertSame('1', $id);
        self::assertSame(901, (int) $this->admin->query("SELECT MAX(id) FROM {$this->schema}.audit")->fetchColumn());
    }

    public function testInsertWithoutReturningIsRefusedBeforeAnyRowIsWritten(): void
    {
        try {
            $this->db->insertGetId('INSERT INTO items (label) VALUES (?)', ['second']);
            self::fail('expected the statement without RETURNING to be refused');
        } catch (DatabaseException $e) {
            self::assertStringContainsString('INSERT ... RETURNING id', $e->getMessage());
            self::assertStringNotContainsString('second', $e->getMessage());
        }

        self::assertSame(0, (int) $this->admin->query("SELECT COUNT(*) FROM {$this->schema}.items")->fetchColumn());
    }

    public function testConflictDoNothingReturningYieldsFalseOnDuplicate(): void
    {
        $this->db->insertGetId('INSERT INTO items (label) VALUES (?) RETURNING id', ['dup']);

        $id = $this->db->insertGetId(
            'INSERT INTO items (label) VALUES (?) ON CONFLICT (label) DO NOTHING RETURNING id',
            ['dup'],
        );

        self::assertFalse($id);
    }

    public function testReturningWordInsideALiteralIsRefusedAfterItRuns(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db->insertGetId("INSERT INTO items (label) VALUES ('RETURNING id')", []);
    }

    public function testLastInsertIdIsRefusedOnPostgresEvenAfterAReturningInsert(): void
    {
        $this->db->insertGetId('INSERT INTO items (label) VALUES (?) RETURNING id', ['first']);

        $this->expectException(DatabaseException::class);
        $this->db->lastInsertId();
    }

    public function testRefusalInsideTransactionLeavesTheTransactionUsable(): void
    {
        $refused = false;

        $this->db->transaction(function (Database $db) use (&$refused): void {
            try {
                $db->insertGetId('INSERT INTO items (label) VALUES (?)', ['never']);
            } catch (DatabaseException) {
                $refused = true;
            }

            $db->insertGetId('INSERT INTO items (label) VALUES (?) RETURNING id', ['kept']);
        });

        self::assertTrue($refused);
        self::assertSame(
            ['kept'],
            $this->admin->query("SELECT label FROM {$this->schema}.items")->fetchAll(PDO::FETCH_COLUMN),
        );
    }
}
