<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionProperty;
use Zephyrus\Core\Config\DatabaseConfig;
use Zephyrus\Data\Database;

/**
 * Verifies the PROCESS-WIDE column shape cache added to Database, on top of the
 * per-instance memo already covered by DatabaseColumnTypeCacheTest.
 *
 * The three failure modes this layer had to avoid are each pinned by a test:
 *
 *   1. Caching converters instead of metadata: the cached value must stay plain
 *      data, so testCachedShapeIsPlainSerializableData asserts it survives
 *      serialize() (a cached Closure would throw outright).
 *   2. Poisoning one instance's converters with another's: two Database
 *      instances on the same DSN running the same SQL must each apply their OWN
 *      registry, in BOTH directions (the money guard, and the reverse case
 *      where the first instance has no converter for a type the second one does).
 *   3. Keying on SQL text alone: identical SQL whose column count changed must
 *      re-resolve rather than serve a stale shape.
 *
 * A spy PDOStatement drives a real SQLite result set for the row data while
 * (a) reporting PostgreSQL-style native types via getColumnMeta() so the real
 * converters are exercised, and (b) counting getColumnMeta() calls, which is
 * the resolution work a cache hit is supposed to avoid.
 */
final class DatabaseSharedColumnMetadataTest extends TestCase
{
    private const TWO_COLUMN_TABLE = 'CREATE TABLE records (id INTEGER PRIMARY KEY, price TEXT)';

    protected function setUp(): void
    {
        // Isolate the static between tests so ordering cannot make one pass
        // vacuously off another test's warm entries.
        Database::setSharedColumnMetadataEnabled(true);
        Database::flushSharedColumnMetadata();
        MetaSpyStatement::reset();
    }

    protected function tearDown(): void
    {
        Database::setSharedColumnMetadataEnabled(true);
        Database::flushSharedColumnMetadata();
        MetaSpyStatement::reset();
    }

    // ── the shared layer actually avoids the metadata work ───────────────────

    public function testSecondInstanceOnTheSameDsnResolvesWithoutTouchingTheBackend(): void
    {
        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $cold = $this->makeDatabase('shared_hit', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($cold);
        $coldRow = $cold->selectOne($sql);

        $callsAfterCold = MetaSpyStatement::$calls;
        self::assertSame(2, $callsAfterCold, 'A cold resolve must read both columns.');

        // A brand new instance: its own per-instance memo is empty, so without
        // the shared layer this would re-read every column from the backend.
        $warm = $this->makeDatabase('shared_hit', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($warm);
        $warmRow = $warm->selectOne($sql);

        self::assertSame(
            $callsAfterCold,
            MetaSpyStatement::$calls,
            'A shared cache hit must not call getColumnMeta() again.',
        );
        self::assertEquals($coldRow, $warmRow, 'A warm hit must produce the same row as a cold resolve.');
    }

    public function testWarmHitMatchesColdResolveAcrossBuiltinJsonAndNumericColumns(): void
    {
        MetaSpyStatement::$nativeTypeByName = [
            'id'      => 'INT4',     // builtin, intval
            'payload' => 'JSONB',    // builtin closure, json_decode
            'price'   => 'NUMERIC',  // builtin, floatval
            'flag'    => 'BOOL',     // builtin, boolval
            'tags'    => '_TEXT',    // PostgreSQL array, dynamic closure
            'label'   => 'TEXT',     // no converter at all
        ];
        $sql = 'SELECT id, payload, price, flag, tags, label FROM records WHERE id = 1';

        $cold = $this->makeDatabase('mixed_types', self::MIXED_TABLE);
        $this->seedMixedRow($cold);
        $coldRow = $cold->selectOne($sql);

        $warm = $this->makeDatabase('mixed_types', self::MIXED_TABLE);
        $this->seedMixedRow($warm);
        $warmRow = $warm->selectOne($sql);

        // Every conversion family still lands where it should.
        self::assertSame(1, $warmRow->id);
        self::assertSame(7, $warmRow->payload->a);
        self::assertSame(12.5, $warmRow->price);
        self::assertTrue($warmRow->flag);
        self::assertSame(['x', 'y'], $warmRow->tags);
        self::assertSame('plain', $warmRow->label);

        self::assertEquals($coldRow, $warmRow);
    }

    public function testFlushForcesTheNextResolveToReadMetadataAgain(): void
    {
        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $first = $this->makeDatabase('flushable', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($first);
        $first->selectOne($sql);
        $afterWarm = MetaSpyStatement::$calls;

        Database::flushSharedColumnMetadata();

        $second = $this->makeDatabase('flushable', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($second);
        $second->selectOne($sql);

        self::assertGreaterThan(
            $afterWarm,
            MetaSpyStatement::$calls,
            'A flushed store must send the next resolve back to the backend.',
        );
    }

    // ── trap 1: the cached value must be plain data, never a converter ───────

    public function testCachedShapeIsPlainSerializableData(): void
    {
        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];

        $db = $this->makeDatabase('plain_data', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);
        $db->selectOne('SELECT id, price FROM records WHERE id = 1');

        $store = self::sharedStore();
        self::assertCount(1, $store);

        $shape = array_values($store)[0];
        self::assertSame(2, $shape['count']);
        self::assertSame(['id' => 'INT4', 'price' => 'NUMERIC'], $shape['types']);

        // A cached Closure would make this throw, not return false. Caching the
        // converters directly is exactly what this layer must never do.
        self::assertIsString(serialize($shape));
    }

    public function testShapeRecordsEveryColumnIncludingOnesWithNoConverter(): void
    {
        // 'label' has no registered converter anywhere. It must still be part of
        // the cached shape, otherwise an instance that later registers a TEXT
        // converter would read back a shape that no longer mentions the column.
        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'TEXT'];

        $db = $this->makeDatabase('full_shape', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);
        $db->selectOne('SELECT id, price FROM records WHERE id = 1');

        $shape = array_values(self::sharedStore())[0];

        self::assertArrayHasKey('price', $shape['types']);
        self::assertSame('TEXT', $shape['types']['price']);
    }

    // ── trap 2: an instance may only ever apply its OWN converters ───────────

    public function testTwoInstancesWithDifferentConversionsEachApplyTheirOwn(): void
    {
        // THE money guard. The framework default maps NUMERIC to floatval; an
        // application overrides it with a string passthrough precisely so money
        // never rounds through a float. Sharing a cache between the two must
        // never let one instance serve the other's converter.
        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $framework = $this->makeDatabase('money', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($framework, '12.50');
        $frameworkRow = $framework->selectOne($sql);

        $callsAfterFirst = MetaSpyStatement::$calls;

        $application = $this->makeDatabase('money', self::TWO_COLUMN_TABLE);
        $application->registerTypeConversion('NUMERIC', static fn (string $v): string => $v);
        $this->seedTwoColumnRow($application, '12.50');
        $applicationRow = $application->selectOne($sql);

        // Same DSN, same SQL, same width: the shape genuinely came from cache,
        // so this assertion is not passing vacuously on a second cold resolve.
        self::assertSame(
            $callsAfterFirst,
            MetaSpyStatement::$calls,
            'Both instances must be reading the same cached shape.',
        );

        self::assertIsFloat($frameworkRow->price);
        self::assertSame(12.5, $frameworkRow->price);

        self::assertIsString($applicationRow->price);
        self::assertSame('12.50', $applicationRow->price);
    }

    public function testConverterRegisteredOnlyByTheSecondInstanceStillApplies(): void
    {
        // The reverse direction, and the reason the shape records every column:
        // the FIRST instance has no BYTEA converter. Had it cached only the
        // columns it could convert, the second instance would read back a shape
        // with no bytea column in it and its converter would silently never run.
        MetaSpyStatement::$nativeTypeByName = ['price' => 'BYTEA'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $without = $this->makeDatabase('bytea', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($without, '\\x48656c6c6f');
        $withoutRow = $without->selectOne($sql);

        // No BYTEA converter registered, so the raw value passes through.
        self::assertSame('\\x48656c6c6f', $withoutRow->price);

        $callsAfterFirst = MetaSpyStatement::$calls;

        $with = $this->makeDatabase('bytea', self::TWO_COLUMN_TABLE);
        $with->registerTypeConversion('BYTEA', static fn (string $v): string => ltrim($v, '\\x'));
        $this->seedTwoColumnRow($with, '\\x48656c6c6f');
        $withRow = $with->selectOne($sql);

        self::assertSame(
            $callsAfterFirst,
            MetaSpyStatement::$calls,
            'The second instance must be reading the cached shape.',
        );
        self::assertSame('48656c6c6f', $withRow->price);
    }

    public function testRegisterTypeConversionTakesEffectImmediatelyOnAWarmSharedCache(): void
    {
        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $db = $this->makeDatabase('late_register', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db, '7.00');

        // Warm both layers with the builtin NUMERIC conversion.
        self::assertSame(7.0, $db->selectOne($sql)->price);

        $callsAfterWarm = MetaSpyStatement::$calls;

        $db->registerTypeConversion('NUMERIC', static fn (string $v): string => $v);

        // The converter changes how a type converts, never what type a column
        // is, so the shape stays valid: the new behaviour must appear WITHOUT
        // re-reading metadata.
        self::assertSame('7.00', $db->selectOne($sql)->price);
        self::assertSame(
            $callsAfterWarm,
            MetaSpyStatement::$calls,
            'Registering a converter must not invalidate the cached shape.',
        );
    }

    // ── trap 3: SQL text alone is not a sufficient key ───────────────────────

    public function testColumnCountChangeForTheSameSqlInvalidatesTheShape(): void
    {
        // Identical SQL text, identical DSN, one extra column: the exact shape
        // of a migration landing before the process restarts.
        $sql = 'SELECT * FROM records';

        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];
        $before = $this->makeDatabase('migrated', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($before, '3.50');
        $beforeRow = $before->select($sql)[0];

        self::assertSame(3.5, $beforeRow->price);
        self::assertFalse(property_exists($beforeRow, 'quantity'));

        $callsAfterFirst = MetaSpyStatement::$calls;

        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC', 'quantity' => 'INT4'];
        $after = $this->makeDatabase(
            'migrated',
            'CREATE TABLE records (id INTEGER PRIMARY KEY, price TEXT, quantity TEXT)',
        );
        $after->query("INSERT INTO records (id, price, quantity) VALUES (1, '3.50', '42')");
        $afterRow = $after->select($sql)[0];

        self::assertGreaterThan(
            $callsAfterFirst,
            MetaSpyStatement::$calls,
            'A different column count must re-resolve rather than serve the stale shape.',
        );
        self::assertSame(3.5, $afterRow->price);
        self::assertSame(42, $afterRow->quantity, 'The new column must be converted, not left a raw string.');
    }

    public function testDifferentDsnsDoNotShareAShape(): void
    {
        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $alpha = $this->makeDatabase('alpha', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($alpha);
        $alpha->selectOne($sql);

        $callsAfterAlpha = MetaSpyStatement::$calls;

        $beta = $this->makeDatabase('beta', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($beta);
        $beta->selectOne($sql);

        self::assertGreaterThan(
            $callsAfterAlpha,
            MetaSpyStatement::$calls,
            'Two databases on the same host must not share a cached shape.',
        );
        self::assertCount(2, self::sharedStore(), 'Each DSN gets its own entry.');
    }

    // ── duplicate column names ───────────────────────────────────────────────

    public function testDuplicateColumnNamesResolveAgainstTheColumnThatActuallyWins(): void
    {
        // Registry-independent shapes changed one edge case, in the direction of
        // correctness. With two result columns sharing a name, PDO::FETCH_OBJ
        // keeps the LAST one's value, so the LAST one's type is the type that
        // matters. The previous resolution recorded a converter only for columns
        // it could convert, so an earlier INT4 column kept ownership of the name
        // and intval() was applied to a value that came from the later column:
        // a UUID string silently became 0. The shape now records every column,
        // last one wins, and the value is left alone.
        MetaSpyStatement::$nativeTypeByIndex = [0 => 'INT4', 1 => 'UUID'];

        $db = $this->makeDatabase('duplicate_names', self::TWO_COLUMN_TABLE);
        $db->query("INSERT INTO records (id, price) VALUES (7, 'a3f2-not-a-number')");

        $row = $db->selectOne('SELECT id, price AS id FROM records');

        self::assertSame('a3f2-not-a-number', $row->id);
    }

    // ── the escape hatch, and equivalence with the layer off ─────────────────

    public function testResultsAreIdenticalWithTheSharedLayerDisabledAndEnabled(): void
    {
        MetaSpyStatement::$nativeTypeByName = [
            'id'      => 'INT4',
            'payload' => 'JSONB',
            'price'   => 'NUMERIC',
            'flag'    => 'BOOL',
            'tags'    => '_TEXT',
            'label'   => 'TEXT',
        ];
        $sql = 'SELECT id, payload, price, flag, tags, label FROM records WHERE id = 1';

        Database::setSharedColumnMetadataEnabled(false);
        $off = $this->makeDatabase('toggle', self::MIXED_TABLE);
        $this->seedMixedRow($off);
        $offRow = $off->selectOne($sql);

        self::assertCount(0, self::sharedStore(), 'A disabled layer must not be populated.');

        Database::setSharedColumnMetadataEnabled(true);
        $onCold = $this->makeDatabase('toggle', self::MIXED_TABLE);
        $this->seedMixedRow($onCold);
        $onColdRow = $onCold->selectOne($sql);

        $onWarm = $this->makeDatabase('toggle', self::MIXED_TABLE);
        $this->seedMixedRow($onWarm);
        $onWarmRow = $onWarm->selectOne($sql);

        self::assertEquals($offRow, $onColdRow);
        self::assertEquals($offRow, $onWarmRow);
    }

    public function testDisablingTheLayerAlsoReleasesWhatItHeld(): void
    {
        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];

        $db = $this->makeDatabase('released', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);
        $db->selectOne('SELECT id, price FROM records WHERE id = 1');

        self::assertCount(1, self::sharedStore());

        Database::setSharedColumnMetadataEnabled(false);

        self::assertCount(0, self::sharedStore());
    }

    public function testSharedStoreStaysBoundedUnderUnboundedDistinctSql(): void
    {
        // A long-lived process generating dynamic SQL (varying IN lists,
        // generated filters) must not grow this store forever. The cap resets
        // it wholesale rather than carrying LRU bookkeeping on the hot path.
        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];

        $cap = (new ReflectionClass(Database::class))->getConstant('MAX_SHARED_COLUMN_SHAPES');
        self::assertIsInt($cap);

        $db = $this->makeDatabase('unbounded', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);

        $peak = 0;

        for ($i = 0; $i <= $cap + 2; $i++) {
            // Distinct SQL TEXT on every pass, so every pass is a new shape.
            $db->selectOne('SELECT id, price FROM records WHERE id = 1 AND ' . $i . ' = ' . $i);
            $peak = max($peak, count(self::sharedStore()));
        }

        self::assertLessThanOrEqual($cap, $peak, 'The store must never exceed its cap.');
        self::assertLessThan($cap, count(self::sharedStore()), 'Passing the cap must reset the store.');
    }

    public function testDirectlyInjectedPdoNeverTouchesTheSharedLayer(): void
    {
        // A PDO handed to the constructor has no knowable identity, so two
        // unrelated databases could collide on the same SQL. Those instances
        // keep the per-instance memo alone, exactly as before this cache.
        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $first = new Database($this->makePdo(self::TWO_COLUMN_TABLE));
        $this->seedTwoColumnRow($first);
        $first->selectOne($sql);

        $callsAfterFirst = MetaSpyStatement::$calls;
        self::assertCount(0, self::sharedStore());

        $second = new Database($this->makePdo(self::TWO_COLUMN_TABLE));
        $this->seedTwoColumnRow($second);
        $second->selectOne($sql);

        self::assertGreaterThan($callsAfterFirst, MetaSpyStatement::$calls);
        self::assertCount(0, self::sharedStore());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private const MIXED_TABLE = 'CREATE TABLE records ('
        . 'id INTEGER PRIMARY KEY, payload TEXT, price TEXT, flag TEXT, tags TEXT, label TEXT)';

    /**
     * Open a Database through fromConfig() so it carries a DSN, backed by a
     * fresh in-memory SQLite connection whose statements report PostgreSQL
     * native types.
     */
    private function makeDatabase(string $databaseName, string $ddl): Database
    {
        $config = DatabaseConfig::fromArray([
            'database' => $databaseName,
            'username' => 'app',
        ]);

        $pdo = $this->makePdo($ddl);

        // fromConfig() issues a SET client_encoding that SQLite rejects; the
        // framework swallows that, and it resolves no column metadata.
        return Database::fromConfig(
            $config,
            static fn (string $dsn, string $username, string $password, array $options): PDO => $pdo,
        );
    }

    private function makePdo(string $ddl): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [MetaSpyStatement::class, []]);
        $pdo->exec($ddl);

        return $pdo;
    }

    // Writes never resolve column metadata, so the getColumnMeta() counter is
    // untouched by seeding and stays a cumulative count of resolution work.

    private function seedTwoColumnRow(Database $db, string $price = '12.50'): void
    {
        $db->query('INSERT INTO records (id, price) VALUES (1, ?)', [$price]);
    }

    private function seedMixedRow(Database $db): void
    {
        $db->query(
            'INSERT INTO records (id, payload, price, flag, tags, label) VALUES (1, ?, ?, ?, ?, ?)',
            ['{"a":7}', '12.50', 't', '{x,y}', 'plain'],
        );
    }

    /**
     * @return array<string, array{count: int, types: array<string, string>}>
     */
    private static function sharedStore(): array
    {
        /** @var array<string, array{count: int, types: array<string, string>}> $store */
        $store = (new ReflectionProperty(Database::class, 'sharedColumnMetadata'))->getValue();

        return $store;
    }
}

/**
 * A PDOStatement that spies on getColumnMeta():
 *   - counts calls, which is the backend metadata work a cache hit must avoid,
 *   - overrides the reported native_type per column name, so the PostgreSQL
 *     converters can be exercised against a SQLite-backed result set.
 *
 * Named apart from DatabaseColumnTypeCacheTest's SpyStatement because both live
 * in this namespace and the two suites count calls independently.
 */
final class MetaSpyStatement extends PDOStatement
{
    /** @var array<string, string> column name → forced native_type */
    public static array $nativeTypeByName = [];

    /** @var array<int, string> column index → forced native_type, wins over the name map */
    public static array $nativeTypeByIndex = [];

    public static int $calls = 0;

    // PDOStatement's constructor is protected and must be declared as such.
    protected function __construct()
    {
    }

    public static function reset(): void
    {
        self::$nativeTypeByName = [];
        self::$nativeTypeByIndex = [];
        self::$calls = 0;
    }

    public function getColumnMeta(int $column): array|false
    {
        self::$calls++;

        $meta = parent::getColumnMeta($column);
        if ($meta === false) {
            return false;
        }

        if (isset(self::$nativeTypeByIndex[$column])) {
            $meta['native_type'] = self::$nativeTypeByIndex[$column];

            return $meta;
        }

        $name = $meta['name'] ?? '';
        if (isset(self::$nativeTypeByName[$name])) {
            $meta['native_type'] = self::$nativeTypeByName[$name];
        }

        return $meta;
    }
}
