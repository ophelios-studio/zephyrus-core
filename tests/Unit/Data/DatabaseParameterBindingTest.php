<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOStatement;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Stringable;
use Zephyrus\Data\Binary;
use Zephyrus\Data\Database;
use Zephyrus\Data\DatabaseException;

/**
 * SQLite reports the storage class a parameter arrived with through typeof(),
 * which makes the PDO type each value was bound with observable.
 */
final class DatabaseParameterBindingTest extends TestCase
{
    private Database $db;

    protected function setUp(): void
    {
        $this->db = new Database(new PDO('sqlite::memory:'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function boundTypes(): iterable
    {
        yield 'true' => [true, 'integer'];
        yield 'false' => [false, 'integer'];
        yield 'int' => [42, 'integer'];
        yield 'zero' => [0, 'integer'];
        yield 'negative int' => [-7, 'integer'];
        yield 'max int' => [PHP_INT_MAX, 'integer'];
        yield 'null' => [null, 'null'];
        yield 'binary' => [new Binary("\x00\xff\x80"), 'blob'];
        yield 'empty binary' => [new Binary(''), 'blob'];
        yield 'string' => ['Zoë', 'text'];
        yield 'numeric string' => ['42', 'text'];
        yield 'zero string' => ['0', 'text'];
        yield 'empty string' => ['', 'text'];
        yield 'float' => [1.5, 'text'];
        yield 'stringable' => [new class () implements Stringable {
            public function __toString(): string
            {
                return 'stringable';
            }
        }, 'text'];
    }

    #[DataProvider('boundTypes')]
    public function testAPositionalParameterIsBoundWithTheTypeOfItsValue(mixed $value, string $storageClass): void
    {
        self::assertSame($storageClass, $this->db->selectValue('SELECT typeof(?)', [$value]));
    }

    #[DataProvider('boundTypes')]
    public function testANamedParameterIsBoundWithTheTypeOfItsValue(mixed $value, string $storageClass): void
    {
        self::assertSame($storageClass, $this->db->selectValue('SELECT typeof(:value)', ['value' => $value]));
    }

    #[DataProvider('boundTypes')]
    public function testANamedParameterKeyedWithItsColonIsBoundWithTheTypeOfItsValue(mixed $value, string $storageClass): void
    {
        self::assertSame($storageClass, $this->db->selectValue('SELECT typeof(:value)', [':value' => $value]));
    }

    public function testBooleansArriveAsOneAndZero(): void
    {
        $row = $this->db->selectOne('SELECT ? AS yes, ? AS no', [true, false]);

        self::assertNotNull($row);
        self::assertSame(1, $row->yes);
        self::assertSame(0, $row->no);
    }

    public function testABooleanIsBoundAsAnIntegerSoEveryColumnTypeReadsItBack(): void
    {
        self::assertSame([1 => [1, PDO::PARAM_INT], 2 => [0, PDO::PARAM_INT]], $this->boundBy('SELECT ?, ?', [true, false]));
    }

    public function testBooleansAreStoredAsOneAndZeroInAnIntegerColumn(): void
    {
        $this->db->execute('CREATE TABLE flag (id INTEGER PRIMARY KEY, active INTEGER NOT NULL)');
        $this->db->execute('INSERT INTO flag (id, active) VALUES (?, ?), (?, ?)', [1, true, 2, false]);

        self::assertSame(1, $this->db->selectValue('SELECT id FROM flag WHERE active = 1'));
        self::assertSame(2, $this->db->selectValue('SELECT id FROM flag WHERE active = 0'));
        self::assertSame(2, $this->db->selectValue('SELECT id FROM flag WHERE active = ?', [false]));
    }

    public function testBinaryBytesRoundTripUnchanged(): void
    {
        $bytes = "\x00" . random_bytes(64) . "\xff\xfe\x00";
        $this->db->execute('CREATE TABLE blob_store (payload BLOB NOT NULL)');
        $this->db->execute('INSERT INTO blob_store (payload) VALUES (?)', [new Binary($bytes)]);

        self::assertSame($bytes, $this->db->selectValue('SELECT payload FROM blob_store'));
    }

    public function testALargeBinaryValueRoundTripsUnchanged(): void
    {
        $bytes = random_bytes(1024 * 1024);

        self::assertSame($bytes, $this->db->selectValue('SELECT :payload', [':payload' => new Binary($bytes)]));
    }

    public function testValuesOfEveryTypeBindInOneStatement(): void
    {
        $row = $this->db->selectOne(
            'SELECT typeof(:a) AS a, typeof(:b) AS b, typeof(:c) AS c, typeof(:d) AS d, typeof(:e) AS e, typeof(:f) AS f',
            ['a' => true, ':b' => 3, 'c' => null, ':d' => new Binary('x'), 'e' => 'x', 'f' => 2.5],
        );

        self::assertNotNull($row);
        self::assertSame(
            ['a' => 'integer', 'b' => 'integer', 'c' => 'null', 'd' => 'blob', 'e' => 'text', 'f' => 'text'],
            (array) $row,
        );
    }

    public function testPositionalParametersKeepTheirOrder(): void
    {
        self::assertSame(
            ['a' => 'first', 'b' => 2, 'c' => 'third'],
            (array) $this->db->selectOne('SELECT ? AS a, ? AS b, ? AS c', ['first', 2, 'third']),
        );
    }

    public function testAPositionalListThatDoesNotStartAtZeroStillFailsAsAQueryError(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db->selectValue('SELECT ?', [1 => 'x']);
    }

    public function testANamedParameterMissingFromTheStatementStillFailsAsAQueryError(): void
    {
        $this->expectException(DatabaseException::class);

        $this->db->selectValue('SELECT :present', ['present' => 1, 'absent' => 2]);
    }

    public function testFloatsKeepTheirTextForm(): void
    {
        self::assertSame('0.1', $this->db->selectValue('SELECT ?', [0.1]));
    }

    /**
     * @return iterable<string, array{float, string}>
     */
    public static function floatLiterals(): iterable
    {
        yield 'one third keeps all its digits' => [1 / 3, '0.3333333333333333'];
        yield 'binary rounding is kept' => [0.1 + 0.2, '0.30000000000000004'];
        yield 'integral float is spelled as an integer' => [45.0, '45'];
        yield 'negative integral float' => [-3.0, '-3'];
        yield 'negative zero' => [-0.0, '-0'];
        yield 'large integral float below 2^53' => [9007199254740991.0, '9007199254740991'];
        yield 'large float uses an exponent' => [1.0e25, '1.0e+25'];
        yield 'small float uses an exponent' => [1.5e-7, '1.5e-7'];
        yield 'largest double' => [PHP_FLOAT_MAX, '1.7976931348623157e+308'];
        yield 'not a number' => [NAN, 'NaN'];
        yield 'positive infinity' => [INF, 'Infinity'];
        yield 'negative infinity' => [-INF, '-Infinity'];
    }

    #[DataProvider('floatLiterals')]
    public function testAFloatBindsAsTheShortestTextThatReadsBackExactly(float $value, string $literal): void
    {
        $bound = $this->db->selectValue('SELECT ?', [$value]);

        self::assertSame($literal, $bound);

        if (is_finite($value)) {
            self::assertSame($value, (float) $bound);
        }
    }

    public function testAFloatIgnoresTheNumericLocale(): void
    {
        $previous = setlocale(LC_NUMERIC, '0');
        if (setlocale(LC_NUMERIC, 'de_DE.UTF-8', 'de_DE', 'fr_FR.UTF-8', 'fr_FR') === false) {
            self::markTestSkipped('No locale with a decimal comma is installed.');
        }

        try {
            self::assertSame('0.5', $this->db->selectValue('SELECT ?', [0.5]));
        } finally {
            setlocale(LC_NUMERIC, (string) $previous);
        }
    }

    public function testADateBindsWithMicrosecondsAndItsOffset(): void
    {
        $date = new \DateTimeImmutable('2026-10-09 15:18:12.123456', new \DateTimeZone('America/Toronto'));

        self::assertSame('2026-10-09 15:18:12.123456-04:00', $this->db->selectValue('SELECT ?', [$date]));
        self::assertSame('2026-10-09 15:18:12.123456-04:00', $this->db->selectValue('SELECT :at', ['at' => \DateTime::createFromImmutable($date)]));
    }

    public function testABackedEnumBindsAsItsValue(): void
    {
        $row = $this->db->selectOne('SELECT ? AS label, typeof(?) AS label_type, ? AS rank, typeof(?) AS rank_type', [
            BindingTestStatus::Active,
            BindingTestStatus::Active,
            BindingTestRank::High,
            BindingTestRank::High,
        ]);

        self::assertNotNull($row);
        self::assertSame(['label' => 'active', 'label_type' => 'text', 'rank' => 3, 'rank_type' => 'integer'], (array) $row);
    }

    public function testAStreamBindsAsBinary(): void
    {
        $bytes = "\x00\xff" . random_bytes(32);
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fwrite($stream, $bytes);
        rewind($stream);

        $row = $this->db->selectOne('SELECT ? AS payload, typeof(?) AS type', [$stream, new Binary('')]);

        self::assertNotNull($row);
        self::assertSame($bytes, $row->payload);
        self::assertSame('blob', $row->type);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function valuesWithNoSqlForm(): iterable
    {
        yield 'array' => [['secret-value']];
        yield 'non-backed enum' => [BindingTestUnit::Only];
        yield 'plain object' => [(object) ['secret' => 'secret-value']];
        yield 'closure' => [static fn (): string => 'secret-value'];
    }

    #[DataProvider('valuesWithNoSqlForm')]
    public function testAPositionalValueWithNoSqlFormIsRefusedNamingItsPlaceholder(mixed $value): void
    {
        try {
            $this->db->selectValue('SELECT ?, ?', ['first', $value]);
            self::fail('expected the value to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('#2', $e->getMessage());
            self::assertStringNotContainsString('secret-value', $e->getMessage());
        }
    }

    #[DataProvider('valuesWithNoSqlForm')]
    public function testANamedValueWithNoSqlFormIsRefusedNamingItsPlaceholder(mixed $value): void
    {
        try {
            $this->db->selectValue('SELECT :payload', ['payload' => $value]);
            self::fail('expected the value to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(':payload', $e->getMessage());
            self::assertStringNotContainsString('secret-value', $e->getMessage());
        }
    }

    public function testAClosedStreamIsRefused(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);
        fclose($stream);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('#1');

        $this->db->selectValue('SELECT ?', [$stream]);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function textWithANulByte(): iterable
    {
        yield 'nul in the middle' => ["secret\0value"];
        yield 'leading nul' => ["\0secret-value"];
        yield 'trailing nul' => ["secret-value\0"];
        yield 'nul only' => ["\0"];
        yield 'nul after unicode' => ["Zoë\0secret-value"];
        yield 'nul after a megabyte' => [str_repeat('a', 1 << 20) . "\0secret-value"];
        yield 'stringable' => [new class () implements Stringable {
            public function __toString(): string
            {
                return "secret\0value";
            }
        }];
        yield 'backed enum' => [BindingTestNulStatus::Split];
    }

    #[DataProvider('textWithANulByte')]
    public function testAPositionalTextWithANulByteIsRefusedNamingItsPlaceholder(mixed $value): void
    {
        try {
            $this->db->selectValue('SELECT ?, ?', ['first', $value]);
            self::fail('expected the value to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString('#2', $e->getMessage());
            self::assertStringContainsString('Validate request input before querying', $e->getMessage());
            self::assertStringContainsString(Binary::class, $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    #[DataProvider('textWithANulByte')]
    public function testANamedTextWithANulByteIsRefusedNamingItsPlaceholder(mixed $value): void
    {
        try {
            $this->db->selectValue('SELECT :payload', ['payload' => $value]);
            self::fail('expected the value to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringContainsString(':payload', $e->getMessage());
            self::assertStringNotContainsString('secret', $e->getMessage());
        }
    }

    public function testATextWithANulByteIsRefusedBeforeAnyStatementRuns(): void
    {
        $this->db->execute('CREATE TABLE account (email TEXT)');

        try {
            $this->db->execute('INSERT INTO account (email) VALUES (?), (?)', ['first@example.com', "second@example.com\0"]);
            self::fail('expected the value to be refused');
        } catch (\InvalidArgumentException) {
        }

        self::assertSame(0, $this->db->selectValue('SELECT COUNT(*) FROM account'));
    }

    public function testBytesWithANulByteStillBindAsBinary(): void
    {
        self::assertSame("a\0b", $this->db->selectValue('SELECT ?', [new Binary("a\0b")]));
    }

    /**
     * @return iterable<string, array{array<int|string, mixed>}>
     */
    public static function invalidKeys(): iterable
    {
        yield 'negative position' => [[-1 => 'secret-value']];
        yield 'largest integer key' => [[PHP_INT_MAX => 'secret-value']];
        yield 'empty name' => [['' => 'secret-value']];
    }

    /**
     * @param array<int|string, mixed> $params
     */
    #[DataProvider('invalidKeys')]
    public function testAnInvalidKeyIsRefusedWithoutEchoingTheValue(array $params): void
    {
        try {
            $this->db->selectValue('SELECT ?', $params);
            self::fail('expected the key to be refused');
        } catch (\InvalidArgumentException $e) {
            self::assertStringNotContainsString('secret-value', $e->getMessage());
        }
    }

    public function testCommonTypesAreBoundWithTheExpectedPdoType(): void
    {
        $stream = fopen('php://memory', 'r+b');
        self::assertIsResource($stream);

        $bound = $this->boundBy('SELECT ?, ?, ?, ?, ?', [
            BindingTestRank::High,
            BindingTestStatus::Active,
            new \DateTimeImmutable('2026-01-02 03:04:05', new \DateTimeZone('UTC')),
            2.5,
            $stream,
        ]);

        self::assertSame([3, PDO::PARAM_INT], $bound[1]);
        self::assertSame(['active', PDO::PARAM_STR], $bound[2]);
        self::assertSame(['2026-01-02 03:04:05.000000+00:00', PDO::PARAM_STR], $bound[3]);
        self::assertSame(['2.5', PDO::PARAM_STR], $bound[4]);
        self::assertSame(PDO::PARAM_LOB, $bound[5][1]);
    }

    /**
     * @param array<int|string, mixed> $params
     * @return array<int|string, array{mixed, int}>
     */
    private function boundBy(string $sql, array $params): array
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS, [BindingSpyStatement::class, []]);
        $stmt = (new Database($pdo))->query($sql, $params);

        self::assertInstanceOf(BindingSpyStatement::class, $stmt);

        return $stmt->bound;
    }
}

/**
 * Records the value and PDO type of every bindValue() call.
 */
final class BindingSpyStatement extends PDOStatement
{
    /** @var array<int|string, array{mixed, int}> */
    public array $bound = [];

    protected function __construct()
    {
    }

    public function bindValue(int|string $param, mixed $value, int $type = PDO::PARAM_STR): bool
    {
        $this->bound[$param] = [$value, $type];

        return parent::bindValue($param, $value, $type);
    }
}

enum BindingTestStatus: string
{
    case Active = 'active';
}

enum BindingTestNulStatus: string
{
    case Split = "secret\0value";
}

enum BindingTestRank: int
{
    case High = 3;
}

enum BindingTestUnit
{
    case Only;
}
