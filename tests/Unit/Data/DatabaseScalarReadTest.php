<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Zephyrus\Data\Database;

final class DatabaseScalarReadTest extends TestCase
{
    public function testSelectValueReturnsAFalseColumnInsteadOfTheDefault(): void
    {
        $db = $this->databaseReturning([[false]]);

        self::assertFalse($db->selectValue('SELECT flag FROM t', [], 'default'));
    }

    public function testSelectValueReturnsTheDefaultWhenNoRowIsReturned(): void
    {
        $db = $this->databaseReturning([]);

        self::assertSame('default', $db->selectValue('SELECT flag FROM t', [], 'default'));
    }

    public function testSelectValueReturnsNullColumnInsteadOfTheDefault(): void
    {
        $db = $this->databaseReturning([[null]]);

        self::assertNull($db->selectValue('SELECT flag FROM t', [], 'default'));
    }

    public function testSelectValueReturnsZeroInsteadOfTheDefault(): void
    {
        $db = $this->databaseReturning([[0]]);

        self::assertSame(0, $db->selectValue('SELECT n FROM t', [], 'default'));
    }

    public function testSelectBoolReturnsTheDefaultWhenNoRowIsReturned(): void
    {
        $db = $this->databaseReturning([]);

        self::assertTrue($db->selectBool('SELECT flag FROM t', [], true));
    }

    public function testSelectBoolReadsAFalseColumnEvenWhenTheDefaultIsTrue(): void
    {
        $db = $this->databaseReturning([[false]]);

        self::assertFalse($db->selectBool('SELECT flag FROM t', [], true));
    }

    public function testSelectBoolReadsAZeroColumnEvenWhenTheDefaultIsTrue(): void
    {
        $db = $this->databaseReturning([[0]]);

        self::assertFalse($db->selectBool('SELECT n FROM t', [], true));
    }

    public function testExistsIsFalseWhenTheGuardColumnIsFalse(): void
    {
        $db = $this->databaseReturning([[false]]);

        self::assertFalse($db->exists('SELECT EXISTS(SELECT 1)'));
    }

    /**
     * @param list<list<mixed>> $rows the rows the statement yields, in order
     */
    private function databaseReturning(array $rows): Database
    {
        return new Database(new ScriptedRowsPdo($rows));
    }
}

/**
 * Hands out a fixed sequence of rows, one per prepared statement, the way a
 * driver would answer a single-column query. Every statement reports the same
 * fetchColumn() semantics as PDO: false for both "no row" and a false column,
 * which is exactly the ambiguity the code under test has to resolve.
 */
final class ScriptedRowsPdo extends PDO
{
    /** @var list<list<mixed>> */
    private array $rows;

    /**
     * @param list<list<mixed>> $rows
     */
    public function __construct(array $rows)
    {
        parent::__construct('sqlite::memory:');

        $this->rows = $rows;
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $row = $this->rows === [] ? null : array_shift($this->rows);

        return new ScriptedRowStatement($row);
    }
}

/**
 * A statement over at most one row. fetchColumn() mirrors PDO and returns false
 * for both "no row" and a false column; fetch() is the unambiguous channel.
 */
final class ScriptedRowStatement extends PDOStatement
{
    /** @param list<mixed>|null $row */
    public function __construct(private readonly ?array $row)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->row ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        return $this->row === null ? false : $this->row[$column];
    }
}
