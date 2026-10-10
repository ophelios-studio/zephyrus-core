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
 * Tests the process-wide column shape cache of Database, layered over the per-instance memo.
 *
 * Guarded failure modes, each covered by a test:
 *   1. Caching converters instead of metadata: the cached shape must stay serializable.
 *   2. Sharing converters between instances on the same DSN: each instance applies its own registry.
 *   3. Keying on SQL text alone: a changed column count must re-resolve.
 *
 * A spy PDOStatement serves real SQLite rows, reports PostgreSQL native types and counts getColumnMeta() calls.
 */
final class DatabaseSharedColumnMetadataTest extends TestCase
{
    private const TWO_COLUMN_TABLE = 'CREATE TABLE records (id INTEGER PRIMARY KEY, price TEXT)';

    protected function setUp(): void
    {
        // The flush must clear APCu too: a warm APCu entry outlives the test.
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

        // Caching converters (closures) would make serialize() throw.
        self::assertIsString(serialize($shape));
    }

    public function testShapeRecordsEveryColumnIncludingOnesWithNoConverter(): void
    {
        // A column without a converter must still be cached, or a converter registered later would read a shape missing it.
        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'TEXT'];

        $db = $this->makeDatabase('full_shape', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);
        $db->selectOne('SELECT id, price FROM records WHERE id = 1');

        $shape = array_values(self::sharedStore())[0];

        self::assertArrayHasKey('price', $shape['types']);
        self::assertSame('TEXT', $shape['types']['price']);
    }

    public function testTwoInstancesWithDifferentConversionsEachApplyTheirOwn(): void
    {
        // The money guard: NUMERIC defaults to floatval, and an application overrides it so money never rounds through a float.
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
        // The first instance has no BYTEA converter: the shape must record every column, or the second converter never runs.
        MetaSpyStatement::$nativeTypeByName = ['price' => 'BYTEA'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $without = $this->makeDatabase('bytea', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($without, '\\x48656c6c6f');
        $withoutRow = $without->selectOne($sql);

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

        self::assertSame(7.0, $db->selectOne($sql)->price);

        $callsAfterWarm = MetaSpyStatement::$calls;

        $db->registerTypeConversion('NUMERIC', static fn (string $v): string => $v);

        // A converter changes how a type converts, not the column type, so the cached shape stays valid.
        self::assertSame('7.00', $db->selectOne($sql)->price);
        self::assertSame(
            $callsAfterWarm,
            MetaSpyStatement::$calls,
            'Registering a converter must not invalidate the cached shape.',
        );
    }

    public function testColumnCountChangeForTheSameSqlInvalidatesTheShape(): void
    {
        // Same SQL text and DSN, one extra column: a migration applied before a restart.
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

    public function testApcuServesTheShapeAfterTheProcessStaticIsGone(): void
    {
        // Simulates a second request: PHP drops every static at shutdown, the persistent PDO handle survives.
        $this->requireApcu();

        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $first = $this->makeDatabase('apcu_request', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($first);
        $firstRow = $first->selectOne($sql);

        $callsAfterFirst = MetaSpyStatement::$calls;
        self::assertSame(2, $callsAfterFirst);

        $cachedShape = array_values(self::sharedStore())[0];
        self::clearProcessStaticOnly();
        self::assertCount(0, self::sharedStore(), 'The static must be empty, as it is at request start.');

        $second = $this->makeDatabase('apcu_request', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($second);
        $secondRow = $second->selectOne($sql);

        self::assertSame(
            $callsAfterFirst,
            MetaSpyStatement::$calls,
            'A warm APCu entry must carry the shape across a cleared static.',
        );
        self::assertEquals($firstRow, $secondRow);

        // The APCu hit refills the static, so later lookups skip shared memory.
        self::assertCount(1, self::sharedStore());
        self::assertSame($cachedShape, array_values(self::sharedStore())[0]);
    }

    public function testApcuKeysAreNamespacedAndFlushSparesForeignEntries(): void
    {
        $this->requireApcu();

        $foreignKey = 'some-host-application-key';
        apcu_store($foreignKey, 'untouched');

        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $db = $this->makeDatabase('namespacing', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);
        $db->selectOne('SELECT id, price FROM records WHERE id = 1');

        $ourKeys = self::apcuKeysWithPrefix();
        self::assertCount(1, $ourKeys);
        self::assertStringStartsWith(self::apcuPrefix(), $ourKeys[0]);

        Database::flushSharedColumnMetadata();

        self::assertCount(0, self::apcuKeysWithPrefix(), 'Our own entries go.');

        $success = false;
        self::assertSame('untouched', apcu_fetch($foreignKey, $success));
        self::assertTrue($success, 'A flush must not touch what the host application stores.');

        apcu_delete($foreignKey);
    }

    public function testFlushClearsApcuNotJustTheStatic(): void
    {
        $this->requireApcu();

        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $first = $this->makeDatabase('apcu_flush', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($first);
        $first->selectOne($sql);

        $callsAfterFirst = MetaSpyStatement::$calls;

        Database::flushSharedColumnMetadata();

        $second = $this->makeDatabase('apcu_flush', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($second);
        $second->selectOne($sql);

        self::assertGreaterThan(
            $callsAfterFirst,
            MetaSpyStatement::$calls,
            'A flush that left APCu warm would silently defeat the escape hatch.',
        );
    }

    public function testDisabledLayerWritesNothingToApcu(): void
    {
        $this->requireApcu();

        Database::setSharedColumnMetadataEnabled(false);

        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $db = $this->makeDatabase('apcu_disabled', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);
        $db->selectOne('SELECT id, price FROM records WHERE id = 1');

        self::assertCount(0, self::apcuKeysWithPrefix());
    }

    public function testMalformedApcuEntryResolvesProperlyRatherThanSkippingConversions(): void
    {
        // A rejected entry must mean "resolve again", never a shape without types, which would silently skip conversions.
        $this->requireApcu();

        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        $warm = $this->makeDatabase('apcu_junk', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($warm);
        $warm->selectOne($sql);

        $key = self::apcuKeysWithPrefix()[0];
        $callsBefore = MetaSpyStatement::$calls;

        foreach ([
            'a bare string',
            false,
            ['count' => 2],
            ['count' => '2', 'types' => ['price' => 'NUMERIC']],
            ['count' => 2, 'types' => 'not-an-array'],
            ['count' => 2, 'types' => ['price' => 12345]],
            ['count' => 99, 'types' => ['price' => 'NUMERIC']],
        ] as $index => $poison) {
            apcu_store($key, $poison);
            self::clearProcessStaticOnly();

            $db = $this->makeDatabase('apcu_junk', self::TWO_COLUMN_TABLE);
            $db->registerTypeConversion('NUMERIC', static fn (string $v): string => $v);
            $this->seedTwoColumnRow($db);
            $row = $db->selectOne($sql);

            self::assertGreaterThan(
                $callsBefore,
                MetaSpyStatement::$calls,
                "Poison #{$index} should have forced a real resolve.",
            );
            self::assertSame('12.50', $row->price, "Poison #{$index} must not cost the conversion.");
            self::assertSame(1, $row->id, "Poison #{$index} must not cost the conversion.");

            $callsBefore = MetaSpyStatement::$calls;
        }
    }

    public function testApcuStoresTheSamePlainShapeTheStaticHolds(): void
    {
        $this->requireApcu();

        MetaSpyStatement::$nativeTypeByName = ['id' => 'INT4', 'price' => 'NUMERIC'];

        $db = $this->makeDatabase('apcu_shape', self::TWO_COLUMN_TABLE);
        $this->seedTwoColumnRow($db);
        $db->selectOne('SELECT id, price FROM records WHERE id = 1');

        $success = false;
        $stored = apcu_fetch(self::apcuKeysWithPrefix()[0], $success);

        self::assertTrue($success);
        self::assertSame(['count' => 2, 'types' => ['id' => 'INT4', 'price' => 'NUMERIC']], $stored);
        self::assertSame(array_values(self::sharedStore())[0], $stored);
    }

    public function testDuplicateColumnNamesResolveAgainstTheColumnThatActuallyWins(): void
    {
        // PDO::FETCH_OBJ keeps the last column sharing a name, so the shape records every column and the last one wins.
        MetaSpyStatement::$nativeTypeByIndex = [0 => 'INT4', 1 => 'UUID'];

        $db = $this->makeDatabase('duplicate_names', self::TWO_COLUMN_TABLE);
        $db->query("INSERT INTO records (id, price) VALUES (7, 'a3f2-not-a-number')");

        $row = $db->selectOne('SELECT id, price AS id FROM records');

        self::assertSame('a3f2-not-a-number', $row->id);
    }

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
        // The store is capped and resets wholesale, so dynamic SQL cannot grow it forever.
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

    public function testSchemaVersionSeparatesSharedShapesAcrossAMigration(): void
    {
        $pdo = $this->makePdo(self::TWO_COLUMN_TABLE);
        $pdo->exec("INSERT INTO records (id, price) VALUES (1, 'abc')");
        $sql = 'SELECT id, price FROM records WHERE id = 1';

        // Before the migration price is INT4, so the converter reads 'abc' as 0.
        MetaSpyStatement::$nativeTypeByName = ['price' => 'INT4'];
        self::assertSame(0, $this->makeDatabaseOn($pdo, '1')->selectOne($sql)->price);

        // The migration turns price into TEXT. The same version keeps the old shape: the documented limit.
        MetaSpyStatement::$nativeTypeByName = ['price' => 'TEXT'];
        self::assertSame(0, $this->makeDatabaseOn($pdo, '1')->selectOne($sql)->price);

        // A new version resolves the column again.
        self::assertSame('abc', $this->makeDatabaseOn($pdo, '2')->selectOne($sql)->price);
    }

    public function testEmptySchemaVersionKeepsTheSharedShapeAcrossInstances(): void
    {
        MetaSpyStatement::$nativeTypeByName = ['price' => 'NUMERIC'];
        $sql = 'SELECT id, price FROM records WHERE id = 1';
        $pdo = $this->makePdo(self::TWO_COLUMN_TABLE);
        $pdo->exec("INSERT INTO records (id, price) VALUES (1, '12.50')");

        $this->makeDatabaseOn($pdo, '')->selectOne($sql);
        $callsAfterCold = MetaSpyStatement::$calls;

        $this->makeDatabaseOn($pdo, '')->selectOne($sql);

        self::assertSame($callsAfterCold, MetaSpyStatement::$calls, 'An empty version must still share the shape.');
    }

    public function testDirectlyInjectedPdoNeverTouchesTheSharedLayer(): void
    {
        // A PDO injected through the constructor has no identity to key on, so it keeps the per-instance memo only.
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

        // fromConfig() runs a SET client_encoding that SQLite rejects; the framework swallows it.
        return Database::fromConfig(
            $config,
            static fn (string $dsn, string $username, string $password, array $options): PDO => $pdo,
        );
    }

    private function makeDatabaseOn(PDO $pdo, string $columnCacheVersion): Database
    {
        $config = DatabaseConfig::fromArray([
            'database' => 'versioned',
            'username' => 'app',
            'columnCacheVersion' => $columnCacheVersion,
        ]);

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

    // Writes never resolve column metadata, so seeding leaves the getColumnMeta() counter alone.

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

    /**
     * Empties the process static only, as PHP does at request shutdown; APCu is kept.
     */
    private static function clearProcessStaticOnly(): void
    {
        (new ReflectionProperty(Database::class, 'sharedColumnMetadata'))->setValue(null, []);
    }

    private static function apcuPrefix(): string
    {
        /** @var string $prefix */
        $prefix = (new ReflectionClass(Database::class))->getConstant('APCU_KEY_PREFIX');

        return $prefix;
    }

    /**
     * @return list<string>
     */
    private static function apcuKeysWithPrefix(): array
    {
        $keys = [];

        foreach (new \APCUIterator('/^' . preg_quote(self::apcuPrefix(), '/') . '/') as $entry) {
            $keys[] = (string) $entry['key'];
        }

        return $keys;
    }

    private function requireApcu(): void
    {
        if (!function_exists('apcu_fetch') || !apcu_enabled()) {
            self::markTestSkipped('APCu is not available (apc.enable_cli is off by default on CLI).');
        }
    }
}

/**
 * PDOStatement counting getColumnMeta() calls and forcing native types, so PostgreSQL converters run on SQLite rows.
 *
 * Distinct from DatabaseColumnTypeCacheTest's SpyStatement, which lives in the same namespace.
 */
final class MetaSpyStatement extends PDOStatement
{
    /** @var array<string, string> column name => forced native_type */
    public static array $nativeTypeByName = [];

    /** @var array<int, string> column index => forced native_type, takes precedence over the name map */
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
