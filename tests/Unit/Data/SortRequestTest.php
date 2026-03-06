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
        $orderSnake = SortRequest::fromArray(['order_by' => 'updated_at', 'order' => 'DESC'], 'id');
        $orderCamel = SortRequest::fromArray(['orderBy' => 'email', 'direction' => 'ASC'], 'id');

        self::assertSame('created_at', $snake->column);
        self::assertSame('ASC', strtoupper($snake->direction));
        self::assertSame('name', $camel->column);
        self::assertSame('DESC', strtoupper($camel->direction));
        self::assertSame('updated_at', $orderSnake->column);
        self::assertSame('DESC', strtoupper($orderSnake->direction));
        self::assertSame('email', $orderCamel->column);
        self::assertSame('ASC', strtoupper($orderCamel->direction));
    }

    public function testFromArrayPrioritizesSortKeysOverOrderAliases(): void
    {
        $sort = SortRequest::fromArray([
            'sort_by' => 'created_at',
            'order_by' => 'name',
            'sort_dir' => 'DESC',
            'order' => 'ASC',
        ], 'id');

        self::assertSame('created_at', $sort->column);
        self::assertSame('DESC', strtoupper($sort->direction));
    }

    public function testFromQuerySupportsOrderAliasesWithAllowlist(): void
    {
        $sort = SortRequest::fromQuery(
            ['order_by' => 'name', 'direction' => 'desc'],
            allowedColumns: ['id', 'name'],
            defaultColumn: 'id',
        );

        self::assertSame('name', $sort->column);
        self::assertSame('DESC', strtoupper($sort->direction));
    }

    public function testFromQuerySupportsCompactSortParameter(): void
    {
        $descending = SortRequest::fromQuery(
            ['sort' => '-name'],
            allowedColumns: ['id', 'name'],
            defaultColumn: 'id',
        );
        $ascending = SortRequest::fromQuery(
            ['sort' => 'created_at'],
            allowedColumns: ['id', 'created_at'],
            defaultColumn: 'id',
        );

        self::assertSame('name', $descending->column);
        self::assertSame('DESC', strtoupper($descending->direction));
        self::assertSame('created_at', $ascending->column);
        self::assertSame('ASC', strtoupper($ascending->direction));
    }

    public function testFromQueryCompactSortKeepsExplicitDirectionPrecedence(): void
    {
        $sort = SortRequest::fromQuery(
            ['sort' => '-name', 'sort_dir' => 'ASC'],
            allowedColumns: ['id', 'name'],
            defaultColumn: 'id',
        );

        self::assertSame('name', $sort->column);
        self::assertSame('ASC', strtoupper($sort->direction));
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
