<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;

/**
 * Verifies the column-metadata cache added to Database:
 *   - type conversions still produce identical types/values (no regression);
 *   - getColumnMeta() (and the PostgreSQL metadata round-trips it triggers)
 *     runs once per distinct query shape, then is served from cache.
 *
 * A spy PDOStatement drives a real SQLite result set for the row data while
 * (a) reporting PostgreSQL-style native types via getColumnMeta() so the real
 * converters are exercised, and (b) counting getColumnMeta() calls so the
 * caching behavior can be asserted.
 */
final class DatabaseColumnTypeCacheTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        SpyStatement::reset();

        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [SpyStatement::class, []]);

        $this->db = new Database($pdo);
        $this->db->query(
            'CREATE TABLE records ('
            . 'id INTEGER PRIMARY KEY, '
            . 'price TEXT, '           // reported as NUMERIC
            . 'created TEXT, '         // reported as DATE
            . 'ref TEXT, '            // reported as UUID (passthrough)
            . 'payload TEXT, '         // reported as JSONB
            . 'blob_col TEXT, '        // reported as BYTEA
            . 'label TEXT)'           // reported as plain TEXT (no converter)
        );
    }

    // ── no regression: conversions still return correct types/values ─────────

    public function testBuiltinNumericConversionStillApplies(): void
    {
        SpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $this->db->query("INSERT INTO records (id, price) VALUES (1, '12.50')");

        $row = $this->db->selectOne('SELECT id, price FROM records WHERE id = 1');

        // Base framework maps NUMERIC → floatval; preserve that exactly.
        self::assertSame(12.5, $row->price);
    }

    public function testRegisteredDecimalAsStringPreservesPrecision(): void
    {
        // Mirrors the money-precision override apps register: NUMERIC → string.
        $this->db->registerTypeConversion('NUMERIC', static fn (string $v): string => $v);

        SpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $this->db->query("INSERT INTO records (id, price) VALUES (1, '19.99')");

        $row = $this->db->selectOne('SELECT id, price FROM records WHERE id = 1');

        self::assertIsString($row->price);
        self::assertSame('19.99', $row->price);
    }

    public function testRegisteredByteaConversionSanitizesValue(): void
    {
        // A bytea-style sanitizer strips the PostgreSQL hex prefix.
        $this->db->registerTypeConversion('BYTEA', static fn (string $v): string => ltrim($v, '\\x'));

        SpyStatement::$nativeTypeByName = ['blob_col' => 'BYTEA'];
        $this->db->query("INSERT INTO records (id, blob_col) VALUES (1, '\\x48656c6c6f')");

        $row = $this->db->selectOne('SELECT id, blob_col FROM records WHERE id = 1');

        self::assertSame('48656c6c6f', $row->blob_col);
    }

    public function testRegisteredDateConversionParses(): void
    {
        $this->db->registerTypeConversion(
            'DATE',
            static fn (string $v): \DateTimeImmutable => new \DateTimeImmutable($v),
        );

        SpyStatement::$nativeTypeByName = ['created' => 'DATE'];
        $this->db->query("INSERT INTO records (id, created) VALUES (1, '2026-08-04')");

        $row = $this->db->selectOne('SELECT id, created FROM records WHERE id = 1');

        self::assertInstanceOf(\DateTimeImmutable::class, $row->created);
        self::assertSame('2026-08-04', $row->created->format('Y-m-d'));
    }

    public function testBuiltinJsonbConversionDecodes(): void
    {
        SpyStatement::$nativeTypeByName = ['payload' => 'JSONB'];
        $this->db->query('INSERT INTO records (id, payload) VALUES (1, \'{"a":1,"b":[2,3]}\')');

        $row = $this->db->selectOne('SELECT id, payload FROM records WHERE id = 1');

        self::assertSame(1, $row->payload->a);
        self::assertSame([2, 3], $row->payload->b);
    }

    public function testUnregisteredTypeIsLeftUntouched(): void
    {
        SpyStatement::$nativeTypeByName = ['label' => 'TEXT'];
        $this->db->query("INSERT INTO records (id, label) VALUES (1, 'plain')");

        $row = $this->db->selectOne('SELECT id, label FROM records WHERE id = 1');

        self::assertSame('plain', $row->label);
    }

    public function testConversionsApplyToEveryRowOfSelect(): void
    {
        SpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $this->db->query("INSERT INTO records (id, price) VALUES (1, '10.00')");
        $this->db->query("INSERT INTO records (id, price) VALUES (2, '20.00')");

        $rows = $this->db->select('SELECT id, price FROM records ORDER BY id');

        self::assertSame(10.0, $rows[0]->price);
        self::assertSame(20.0, $rows[1]->price);
    }

    // ── caching: metadata resolution happens once per query shape ────────────

    public function testColumnMetaResolvedOncePerQueryShapeAcrossSelects(): void
    {
        SpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $this->db->query("INSERT INTO records (id, price) VALUES (1, '5.00')");

        $sql = 'SELECT id, price FROM records ORDER BY id';

        $this->db->select($sql);
        $callsAfterFirst = SpyStatement::$metaCalls;

        // Two columns resolved on the first select.
        self::assertSame(2, $callsAfterFirst);

        // Second identical select must NOT re-resolve — served from cache.
        $this->db->select($sql);
        $this->db->select($sql);

        self::assertSame(
            $callsAfterFirst,
            SpyStatement::$metaCalls,
            'getColumnMeta() should not fire again for a cached query shape.',
        );
    }

    public function testSelectOneAlsoUsesTheCache(): void
    {
        SpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $this->db->query("INSERT INTO records (id, price) VALUES (1, '5.00')");

        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $this->db->selectOne($sql);
        $calls = SpyStatement::$metaCalls;
        self::assertGreaterThan(0, $calls);

        $this->db->selectOne($sql);

        self::assertSame($calls, SpyStatement::$metaCalls);
    }

    public function testDistinctQueriesEachResolveOnce(): void
    {
        SpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $this->db->query("INSERT INTO records (id, price) VALUES (1, '5.00')");

        $this->db->select('SELECT id, price FROM records');       // 2 columns
        $this->db->select('SELECT id FROM records');              // 1 column
        $afterDistinct = SpyStatement::$metaCalls;

        // Re-running both should add nothing.
        $this->db->select('SELECT id, price FROM records');
        $this->db->select('SELECT id FROM records');

        self::assertSame($afterDistinct, SpyStatement::$metaCalls);
    }

    public function testRegisteringConversionInvalidatesCache(): void
    {
        SpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $this->db->query("INSERT INTO records (id, price) VALUES (1, '7.00')");

        $sql = 'SELECT id, price FROM records WHERE id = 1';

        // Prime the cache with the builtin NUMERIC → floatval resolution.
        $first = $this->db->selectOne($sql);
        self::assertSame(7.0, $first->price);

        // Register a new NUMERIC converter after caching; it must take effect.
        $this->db->registerTypeConversion('NUMERIC', static fn (string $v): string => $v);
        $metaCallsBefore = SpyStatement::$metaCalls;

        $second = $this->db->selectOne($sql);

        // Behavior changed (now a string) → cache was invalidated and re-resolved.
        self::assertSame('7.00', $second->price);
        self::assertGreaterThan(
            $metaCallsBefore,
            SpyStatement::$metaCalls,
            'Registering a converter should invalidate the memoized resolution.',
        );
    }
}

/**
 * A PDOStatement that spies on getColumnMeta():
 *   - counts calls (to assert caching),
 *   - overrides the reported native_type per column name (to exercise the
 *     PostgreSQL converters against a SQLite-backed result set).
 */
final class SpyStatement extends PDOStatement
{
    /** @var array<string, string> column name → forced native_type */
    public static array $nativeTypeByName = [];

    public static int $metaCalls = 0;

    // PDOStatement's constructor is protected and must be declared as such.
    protected function __construct()
    {
    }

    public static function reset(): void
    {
        self::$nativeTypeByName = [];
        self::$metaCalls = 0;
    }

    public function getColumnMeta(int $column): array|false
    {
        self::$metaCalls++;

        $meta = parent::getColumnMeta($column);
        if ($meta === false) {
            return false;
        }

        $name = $meta['name'] ?? '';
        if (isset(self::$nativeTypeByName[$name])) {
            $meta['native_type'] = self::$nativeTypeByName[$name];
        }

        return $meta;
    }
}
