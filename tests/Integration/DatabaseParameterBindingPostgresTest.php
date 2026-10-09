<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Integration;

use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Binary;
use Zephyrus\Data\Database;

/**
 * Parameter types against a real PostgreSQL server, which rejects '' for a
 * boolean and reads a text parameter for a bytea column as a bytea literal.
 *
 * Runs only when ZEPHYRUS_TEST_PGSQL_DSN holds a PDO DSN carrying its own
 * credentials. Each test works in a throwaway schema it drops afterwards.
 */
final class DatabaseParameterBindingPostgresTest extends TestCase
{
    private string $schema;
    private PDO $admin;
    private Database $db;

    protected function setUp(): void
    {
        $dsn = getenv('ZEPHYRUS_TEST_PGSQL_DSN');
        if (!is_string($dsn) || $dsn === '') {
            self::markTestSkipped(
                'Set ZEPHYRUS_TEST_PGSQL_DSN (e.g. pgsql:host=127.0.0.1;port=5432;dbname=postgres;user=postgres;password=postgres) to run the PostgreSQL parameter binding tests.',
            );
        }

        $this->schema = 'zephyrus_bind_test_' . bin2hex(random_bytes(6));
        $this->admin = new PDO($dsn);
        $this->admin->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->admin->exec("CREATE SCHEMA {$this->schema}");
        $this->admin->exec(
            "CREATE TABLE {$this->schema}.sample (
                id INTEGER PRIMARY KEY,
                flag BOOLEAN,
                payload BYTEA,
                quantity INTEGER,
                label TEXT,
                document JSONB,
                ratio DOUBLE PRECISION,
                happened_at TIMESTAMPTZ
            )",
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

    public function testFalseAndTrueRoundTripThroughABooleanColumn(): void
    {
        $this->db->execute('INSERT INTO sample (id, flag) VALUES (?, ?), (?, ?)', [1, false, 2, true]);

        self::assertFalse($this->db->selectValue('SELECT flag FROM sample WHERE id = ?', [1]));
        self::assertTrue($this->db->selectValue('SELECT flag FROM sample WHERE id = ?', [2]));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function booleanTargets(): iterable
    {
        foreach (['flag' => 'boolean', 'quantity' => 'integer', 'label' => 'text', 'document' => 'jsonb'] as $column => $type) {
            yield "true into {$type}" => [$column, true];
            yield "false into {$type}" => [$column, false];
        }
    }

    #[DataProvider('booleanTargets')]
    public function testABooleanReadsBackAsItselfFromAnyColumnType(string $column, bool $value): void
    {
        $this->db->execute("INSERT INTO sample (id, {$column}) VALUES (?, ?)", [1, $value]);

        self::assertSame($value, $this->db->selectBool("SELECT {$column} FROM sample WHERE id = ?", [1], !$value));
        self::assertSame(1, $this->db->count("SELECT count(*) FROM sample WHERE {$column} = ?", [$value]));
    }

    public function testFalseFiltersABooleanColumnWithANamedParameter(): void
    {
        $this->db->execute('INSERT INTO sample (id, flag) VALUES (1, false), (2, true)');

        self::assertSame(1, $this->db->selectValue('SELECT id FROM sample WHERE flag = :flag', ['flag' => false]));
        self::assertSame(2, $this->db->selectValue('SELECT id FROM sample WHERE flag = :flag', [':flag' => true]));
    }

    public function testRawBytesRoundTripThroughABinaryParameter(): void
    {
        $bytes = random_bytes(32);

        $this->db->execute('INSERT INTO sample (id, payload) VALUES (?, ?)', [1, new Binary($bytes)]);

        self::assertSame($bytes, $this->readPayload(1));
    }

    public function testBytesThatLookLikeAByteaLiteralAreStoredVerbatim(): void
    {
        $bytes = '\\x00ff' . "\x00\\";

        $this->db->execute('INSERT INTO sample (id, payload) VALUES (:id, :payload)', ['id' => 1, 'payload' => new Binary($bytes)]);

        self::assertSame($bytes, $this->readPayload(1));
    }

    public function testATextWithANulByteIsRefusedRatherThanComparedUpToTheNul(): void
    {
        $this->db->execute('INSERT INTO sample (id, label) VALUES (?, ?)', [1, 'victim@example.com']);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('#1');

        $this->db->selectValue('SELECT id FROM sample WHERE label = ?', ["victim@example.com\0other"]);
    }

    public function testATextWithANulByteIsRefusedRatherThanStoredUpToTheNul(): void
    {
        try {
            $this->db->execute('INSERT INTO sample (id, label) VALUES (:id, :label)', ['id' => 1, 'label' => "kept\0dropped"]);
            self::fail('expected the value to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(':label', $e->getMessage());
        }

        self::assertSame(0, $this->db->selectValue('SELECT COUNT(*) FROM sample'));
    }

    public function testAnEmptyBinaryIsStoredAsAnEmptyByteaRatherThanNull(): void
    {
        $this->db->execute('INSERT INTO sample (id, payload) VALUES (?, ?)', [1, new Binary('')]);

        self::assertSame('', $this->readPayload(1));
    }

    public function testNullIsStoredInANullableIntegerColumn(): void
    {
        $this->db->execute('INSERT INTO sample (id, quantity) VALUES (?, ?)', [1, null]);

        self::assertTrue($this->db->exists('SELECT quantity IS NULL FROM sample WHERE id = ?', [1]));
    }

    public function testAnIntegerIsStoredInAnIntegerColumn(): void
    {
        $this->db->execute('INSERT INTO sample (id, quantity) VALUES (?, ?)', [1, -2147483648]);

        self::assertSame(-2147483648, $this->db->selectValue('SELECT quantity FROM sample WHERE id = ?', [1]));
    }

    public function testStringsAndFloatsStillBindAsText(): void
    {
        $row = $this->db->selectOne('SELECT ?::text AS label, ?::numeric AS amount', ['Zoë', 12.5]);

        self::assertNotNull($row);
        self::assertSame('Zoë', $row->label);
        self::assertSame(12.5, $row->amount);
    }

    public function testAFloatKeepsEveryDigitInADoublePrecisionColumn(): void
    {
        $this->db->execute('INSERT INTO sample (id, ratio) VALUES (?, ?)', [1, 1 / 3]);

        self::assertSame('0.3333333333333333', $this->db->selectValue('SELECT ratio::text FROM sample WHERE id = ?', [1]));
    }

    public function testAnIntegralFloatIsAcceptedByAnIntegerColumn(): void
    {
        $this->db->execute('INSERT INTO sample (id, quantity) VALUES (?, ?)', [1, 45.0]);

        self::assertSame(45, $this->db->selectValue('SELECT quantity FROM sample WHERE id = ?', [1]));
    }

    public function testNonFiniteFloatsUseThePostgresSpelling(): void
    {
        $row = $this->db->selectOne('SELECT ?::float8::text AS nan, ?::float8::text AS inf, ?::numeric::text AS neg', [NAN, INF, -INF]);

        self::assertNotNull($row);
        self::assertSame(['nan' => 'NaN', 'inf' => 'Infinity', 'neg' => '-Infinity'], (array) $row);
    }

    public function testADateKeepsItsInstantAndMicroseconds(): void
    {
        $at = new \DateTimeImmutable('2026-10-09 15:18:12.123456', new \DateTimeZone('America/Toronto'));

        $this->db->execute('INSERT INTO sample (id, happened_at) VALUES (?, ?)', [1, $at]);

        self::assertSame(
            '2026-10-09 19:18:12.123456',
            $this->db->selectValue("SELECT to_char(happened_at AT TIME ZONE 'UTC', 'YYYY-MM-DD HH24:MI:SS.US') FROM sample WHERE id = ?", [1]),
        );
    }

    public function testBackedEnumsBindAsTheirValues(): void
    {
        $this->db->execute('INSERT INTO sample (id, label, quantity) VALUES (:id, :label, :quantity)', [
            'id' => 1,
            'label' => PostgresBindingStatus::Archived,
            'quantity' => PostgresBindingRank::Low,
        ]);

        $row = $this->db->selectOne('SELECT label, quantity FROM sample WHERE id = ?', [1]);

        self::assertNotNull($row);
        self::assertSame(['label' => 'archived', 'quantity' => 1], (array) $row);
    }

    public function testAStreamRoundTripsThroughAByteaColumn(): void
    {
        $bytes = random_bytes(64);
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        $this->db->execute('INSERT INTO sample (id, payload) VALUES (?, ?)', [1, $stream]);

        self::assertSame($bytes, $this->readPayload(1));
    }

    private function readPayload(int $id): string
    {
        $payload = $this->db->selectValue('SELECT payload FROM sample WHERE id = ?', [$id]);

        if (is_resource($payload)) {
            $payload = stream_get_contents($payload);
        }

        self::assertIsString($payload);

        return $payload;
    }
}

enum PostgresBindingStatus: string
{
    case Archived = 'archived';
}

enum PostgresBindingRank: int
{
    case Low = 1;
}
