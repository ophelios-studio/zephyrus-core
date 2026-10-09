<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use stdClass;
use Zephyrus\Data\Database;

final class DatabaseArrayReadTest extends TestCase
{
    public function testATextArrayColumnIsDecoded(): void
    {
        $db = new Database(new TextArrayPdo('{"Rue du Pont, app. 4",NULL}'));

        $rows = $db->select('SELECT v FROM t');

        self::assertSame(['Rue du Pont, app. 4', null], $rows[0]->v);
    }

    public function testNullArrayColumnStaysNull(): void
    {
        $db = new Database(new TextArrayPdo(null));

        $rows = $db->select('SELECT v FROM t');

        self::assertNull($rows[0]->v);
    }
}

/**
 * Answers a single text[] column named v, one row, with the literal given.
 * The native type is _TEXT, which is what pdo_pgsql reports for an array of
 * text, so the array conversion path is the one exercised.
 */
final class TextArrayPdo extends PDO
{
    public function __construct(private readonly ?string $literal)
    {
        parent::__construct('sqlite::memory:');
    }

    /**
     * @param array<int, mixed> $options
     */
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return new TextArrayStatement($this->literal);
    }
}

final class TextArrayStatement extends PDOStatement
{
    public function __construct(private readonly ?string $literal)
    {
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function columnCount(): int
    {
        return 1;
    }

    /**
     * @return array{name: string, native_type: string, len: int, precision: int, pdo_type: int, flags: list<string>}|false
     */
    public function getColumnMeta(int $column): array|false
    {
        return [
            'name' => 'v',
            'native_type' => '_TEXT',
            'len' => -1,
            'precision' => 0,
            'pdo_type' => PDO::PARAM_STR,
            'flags' => [],
        ];
    }

    /**
     * @return list<stdClass>
     */
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $row = new stdClass();
        $row->v = $this->literal;

        return [$row];
    }
}
