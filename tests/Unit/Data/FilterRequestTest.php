<?php

declare(strict_types=1);

namespace Zephyrus\Tests\Unit\Data;

use PHPUnit\Framework\TestCase;
use Zephyrus\Data\FilterRequest;

final class FilterRequestTest extends TestCase
{
    public function testFromQueryKeepsOnlyAllowedNonEmptyKeys(): void
    {
        $filter = FilterRequest::fromQuery([
            'status' => 'active',
            'email' => 'a@example.com',
            'ignored' => 'x',
            'blank' => '',
        ], ['status', 'email', 'blank']);

        self::assertSame([
            'status' => 'active',
            'email' => 'a@example.com',
        ], $filter->toArray());
    }

    public function testToWhereClauseBuildsSqlAndBindings(): void
    {
        $filter = new FilterRequest(['status' => 'active', 'email' => 'a@example.com']);

        $where = $filter->toWhereClause([
            'status' => 'users.status',
            'email' => 'users.email',
        ]);

        self::assertSame(' WHERE users.status = :f_status AND users.email = :f_email', $where['sql']);
        self::assertSame([
            ':f_status' => 'active',
            ':f_email' => 'a@example.com',
        ], $where['params']);
    }

    public function testToWhereClauseIgnoresUnknownMappedKeys(): void
    {
        $filter = new FilterRequest(['status' => 'active', 'role' => 'admin']);

        $where = $filter->toWhereClause([
            'status' => 'users.status',
        ]);

        self::assertSame(' WHERE users.status = :f_status', $where['sql']);
        self::assertSame([':f_status' => 'active'], $where['params']);
    }

    public function testToWhereClauseReturnsEmptyForNoConditions(): void
    {
        $filter = new FilterRequest();
        $where = $filter->toWhereClause(['status' => 'users.status']);

        self::assertSame('', $where['sql']);
        self::assertSame([], $where['params']);
        self::assertTrue($filter->isEmpty());
    }

    public function testJsonSerializeMatchesArray(): void
    {
        $filter = new FilterRequest(['status' => 'active']);

        self::assertSame($filter->toArray(), $filter->jsonSerialize());
    }
}
