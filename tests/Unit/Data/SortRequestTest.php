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

        self::assertSame(' ORDER BY "name" DESC', $sort->toSql());
    }

    public function testToSqlQuotesDottedColumnName(): void
    {
        $sort = new SortRequest('users.name', 'ASC');

        self::assertSame(' ORDER BY "users"."name" ASC', $sort->toSql());
    }

    public function testDirectionIsNormalizedToUppercase(): void
    {
        $sort = new SortRequest('name', 'desc');

        self::assertSame('DESC', $sort->direction);
    }

    /**
     * TRAP-PINNING TEST. This used to be testFromArrayReadsSnakeAndCamelKeys and it
     * blessed the dangerous half of the behaviour without naming it.
     *
     * fromArray() reads the SAME HTTP parameter names as fromQuery()
     * (sort_by / sortBy / order_by / orderBy) but, with no allowlist, accepts ANY
     * identifier-shaped column. Handing it $_GET unfiltered therefore yields a blind
     * ORDER BY oracle: an attacker sorts a listing by a column that is not in the
     * SELECT and reads the hidden ranking off the row order, and a non-existent
     * column turns a 200 into a 42703 error, which is a column-existence oracle.
     *
     * That is NOT arbitrary SQL injection: toSql() double-quotes each dotted part and
     * doubles embedded quotes, and the constructor regex forbids quotes outright, so
     * identifier breakout does not reproduce (see the assertions below). The impact
     * is disclosure plus a 500.
     *
     * fromArray() takes PRE-VALIDATED input ONLY. The optional $allowedColumns
     * argument is what makes it safe on untrusted input, and fromQuery() requires one.
     *
     * The unrestricted assertions here pin the trap deliberately. Do not "fix" them by
     * making the allowlist mandatory without checking every consumer repository first.
     */
    public function testFromArrayReadsSnakeAndCamelKeysAndValidatesNothingWithoutAnAllowlist(): void
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

        // THE TRAP: a column that is in no SELECT, and in no allowlist, is accepted.
        $oracle = SortRequest::fromArray(['sort_by' => 'internal_risk_score'], 'id');
        self::assertSame('internal_risk_score', $oracle->column);
        self::assertSame(' ORDER BY "internal_risk_score" ASC', $oracle->toSql());

        // The bound on the damage: no identifier breakout, because the constructor
        // regex refuses a quote before quoteIdentifier() ever runs.
        $this->expectException(DatabaseException::class);
        $this->expectExceptionMessage('Invalid sort column');
        SortRequest::fromArray(['sort_by' => 'id" , (SELECT 1) --'], 'id');
    }

    /**
     * The BC-safe remedy: pass $allowedColumns and fromArray() restricts the column
     * exactly as fromQuery() does, falling back to the default column.
     */
    public function testFromArrayFallsBackToTheDefaultWhenGivenAnAllowlist(): void
    {
        $rejected = SortRequest::fromArray(
            ['sort_by' => 'internal_risk_score', 'sort_dir' => 'DESC'],
            'id',
            allowedColumns: ['id', 'name'],
        );

        self::assertSame('id', $rejected->column);
        self::assertSame('DESC', $rejected->direction);

        $accepted = SortRequest::fromArray(
            ['sort_by' => 'name'],
            'id',
            allowedColumns: ['id', 'name'],
        );

        self::assertSame('name', $accepted->column);
    }

    public function testFromArrayWithAnEmptyAllowlistFallsBackToTheDefaultColumn(): void
    {
        $sort = SortRequest::fromArray(['sort_by' => 'name'], 'id', allowedColumns: []);

        self::assertSame('id', $sort->column);
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
