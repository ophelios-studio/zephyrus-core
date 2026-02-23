<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Zephyrus\Data\DatabaseException;
use Zephyrus\Data\SortRequest;

final class SortRequestTest extends TestCase
{
    public function testToSqlReturnsSafeOrderByFragment(): void
    {
        $sort = new SortRequest('name', 'desc');

        self::assertSame(' ORDER BY name DESC', $sort->toSql());
    }

    public function testFromArrayReadsSnakeAndCamelKeys(): void
    {
        $snake = SortRequest::fromArray(['sort_by' => 'created_at', 'sort_dir' => 'ASC'], 'id');
        $camel = SortRequest::fromArray(['sortBy' => 'name', 'sortDir' => 'DESC'], 'id');

        self::assertSame('created_at', $snake->column);
        self::assertSame('ASC', strtoupper($snake->direction));
        self::assertSame('name', $camel->column);
        self::assertSame('DESC', strtoupper($camel->direction));
    }

    public function testFromQueryFallsBackToDefaultWhenColumnNotAllowed(): void
    {
        $sort = SortRequest::fromQuery(
            ['sort_by' => 'hacker_column', 'sort_dir' => 'DESC'],
            allowedColumns: ['id', 'name'],
            defaultColumn: 'id',
        );

        self::assertSame('id', $sort->column);
        self::assertSame('DESC', strtoupper($sort->direction));
    }

    public function testToArrayAndJsonSerializeReturnSameEnvelope(): void
    {
        $sort = new SortRequest('id', 'ASC');

        self::assertSame(['sort_by' => 'id', 'sort_dir' => 'ASC'], $sort->toArray());
        self::assertSame($sort->toArray(), $sort->jsonSerialize());
    }

    public function testInvalidColumnThrows(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Invalid sort column');

        new SortRequest('drop table users;');
    }

    public function testInvalidDirectionThrows(): void
    {
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Sort direction must be ASC or DESC');

        new SortRequest('name', 'SIDEWAYS');
    }
}
